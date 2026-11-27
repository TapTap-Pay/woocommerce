<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

/**
 * The store's half of the connect handshake.
 *
 * The store sends the merchant to the TapTap dashboard with a state
 * and a PKCE challenge. On approval TapTap creates the integration,
 * a scoped API key and the webhook subscription, and sends the
 * browser back with a one-time install code. The store then
 * exchanges that code, server to server, for its credentials — they
 * never travel through the browser.
 */
final class Connection
{
    private const TRANSIENT = 'taptap_pay_connection';

    /** The install code lives ten minutes upstream; the handshake is kept as long. */
    private const TTL_SECONDS = 600;

    private const EXCHANGE_PATH = '/taptap.developer.v1.WooCommerceConnectService/ExchangeInstallCode';

    private function __construct()
    {
    }

    /**
     * Starts a handshake and returns the dashboard address to send
     * the merchant to.
     *
     * @throws \RuntimeException when TapTap could not reach this store's webhook route.
     */
    public static function begin(Settings $settings, string $return_url): string
    {
        $shop_url = untrailingslashit(home_url());
        // TapTap derives the webhook address from the shop address, so
        // the route has to live where it will look for it.
        $expected = $shop_url . '/wp-json/' . Plugin::REST_NAMESPACE . '/webhook';
        if (untrailingslashit(rest_url(Plugin::REST_NAMESPACE . '/webhook')) !== $expected) {
            throw new \RuntimeException(
                __('TapTap Pay needs pretty permalinks: choose any structure other than "Plain" under Settings → Permalinks, then try again.', 'taptap-pay')
            );
        }

        $state = wp_generate_password(32, false);
        $verifier = wp_generate_password(64, false);
        set_transient(self::TRANSIENT, ['state' => $state, 'verifier' => $verifier], self::TTL_SECONDS);

        return rtrim($settings->ui_url(), '/') . '/connect/woocommerce?' . http_build_query([
            'shop_url' => $shop_url,
            'return_url' => $return_url,
            'state' => $state,
            'code_challenge' => self::challenge($verifier),
        ]);
    }

    /**
     * True between the merchant leaving for the dashboard and the
     * code exchange. TapTap challenges the webhook endpoint inside
     * that window, before the store holds the signing secret.
     */
    public static function is_pending(): bool
    {
        return is_array(get_transient(self::TRANSIENT));
    }

    /**
     * Ends the handshake and returns its PKCE verifier when $state is
     * the one it was started with, null otherwise. One try: the
     * handshake is gone either way.
     */
    public static function take_verifier(string $state): ?string
    {
        $pending = get_transient(self::TRANSIENT);
        delete_transient(self::TRANSIENT);
        if (!is_array($pending) || $state === '') {
            return null;
        }
        $expected = (string) ($pending['state'] ?? '');
        $verifier = (string) ($pending['verifier'] ?? '');
        if ($expected === '' || $verifier === '' || !hash_equals($expected, $state)) {
            return null;
        }
        return $verifier;
    }

    /**
     * Exchanges the install code for the store's credentials and
     * returns them as a settings patch.
     *
     * @return array{api_key: string, vendor_id: string, webhook_secret: string, webhook_id: string, webhook_verified: bool}
     *
     * @throws \RuntimeException when the exchange is refused or cannot be read.
     */
    public static function exchange(Settings $settings, string $code, string $verifier): array
    {
        $response = wp_remote_post(rtrim($settings->api_url(), '/') . self::EXCHANGE_PATH, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Connect-Protocol-Version' => '1',
            ],
            'body' => (string) wp_json_encode(['code' => $code, 'codeVerifier' => $verifier]),
        ]);
        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status !== 200 || !is_array($body)) {
            // One refusal covers an unknown, expired, used or mismatched
            // code, so there is nothing more specific to tell the merchant.
            throw new \RuntimeException(sprintf('HTTP %d', $status));
        }

        $api_key = (string) ($body['apiKey'] ?? '');
        $vendor_id = (string) ($body['vendorId'] ?? '');
        $webhook_secret = (string) ($body['webhookSigningSecret'] ?? '');
        if ($api_key === '' || $vendor_id === '' || $webhook_secret === '') {
            throw new \RuntimeException('incomplete credentials');
        }

        return [
            'api_key' => $api_key,
            'vendor_id' => $vendor_id,
            'webhook_secret' => $webhook_secret,
            'webhook_id' => (string) ($body['webhookSubscriptionId'] ?? ''),
            'webhook_verified' => (bool) ($body['webhookVerified'] ?? false),
        ];
    }

    /** RFC 7636 S256: unpadded base64url of the verifier's SHA-256. */
    private static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
