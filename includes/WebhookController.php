<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint that receives signed TapTap webhook deliveries.
 *
 * Verification is **HMAC-SHA256 over `<timestamp>.<raw-body>`** with
 * the per-subscription secret. Timestamp and signature arrive together
 * in the `TapTap-Signature` header as `t=<unix>,v1=<hex>`; after a
 * secret rotation the header carries one `v1` tag per live secret and
 * the delivery is genuine if any of them verifies.
 *
 * Three non-obvious choices:
 *
 *   1. We read the raw HTTP body, not the WP REST parsed payload,
 *      because the signature is over the bytes-on-wire and WP's JSON
 *      normalisation would silently alter them.
 *
 *   2. We respond 200 on "couldn't find a matching order" instead of
 *      404. The webhook sender retries on any non-2xx, and a 404 here
 *      is permanent — replaying it forever achieves nothing. The log
 *      line is enough for an operator to investigate.
 *
 *   3. The endpoint challenge is answered unsigned while a connect
 *      handshake is open. TapTap sends it when the merchant approves
 *      the connection, and the store only receives the signing secret
 *      in the exchange that follows. Echoing a nonce proves control
 *      of the URL and discloses nothing.
 */
final class WebhookController
{
    public const TOLERANCE_SECONDS = 300;

    private const CAUSE_PAYMENT_LINK = 'PAYMENT_LINK';

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $raw = self::raw_body($request);
        $payload = json_decode($raw, true);
        $nonce = self::challenge_nonce($payload);

        if ($nonce !== '' && Connection::is_pending()) {
            return self::echo_nonce($nonce);
        }

        $settings = Plugin::settings();
        if ($settings->webhookSecret === '') {
            Logger::warning('webhook hit but no secret configured');
            return new WP_REST_Response(['error' => 'not_configured'], 503);
        }

        $signature = (string) $request->get_header('TapTap-Signature');
        if (!self::verify($raw, $signature, $settings->webhookSecret)) {
            Logger::warning('webhook signature verification failed', [
                'has_signature_header' => $signature !== '',
            ]);
            return new WP_REST_Response(['error' => 'invalid_signature'], 401);
        }

        if (!is_array($payload)) {
            Logger::warning('webhook body is not JSON');
            return new WP_REST_Response(['error' => 'invalid_json'], 400);
        }
        if ($nonce !== '') {
            return self::echo_nonce($nonce);
        }

        $event_type = (string) ($payload['event_type'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $payment_id = (string) ($data['payment_id'] ?? '');
        $link_id = ($data['cause_kind'] ?? '') === self::CAUSE_PAYMENT_LINK
            ? PaymentLookup::wire_link_id((string) ($data['cause_ref'] ?? ''))
            : '';

        if ($payment_id === '' && $link_id === '') {
            Logger::info('webhook named no payment, ignoring', ['event_type' => $event_type]);
            return new WP_REST_Response(['ok' => true], 200);
        }

        try {
            $order = self::find_order($settings, $payment_id, $link_id);
        } catch (\Throwable $e) {
            Logger::error('failed to look up the payment behind a webhook', [
                'payment_id' => $payment_id,
                'event_type' => $event_type,
                'error' => $e->getMessage(),
            ]);
            // The lookup may work on the next attempt; a 500 asks for one.
            return new WP_REST_Response(['error' => 'lookup_failed'], 500);
        }
        if ($order === null) {
            Logger::info('webhook received for unknown order', [
                'payment_id' => $payment_id,
                'event_type' => $event_type,
            ]);
            return new WP_REST_Response(['ok' => true], 200);
        }

        try {
            OrderUpdater::apply_event($order, $event_type, $payload);
        } catch (\Throwable $e) {
            Logger::error('failed to apply webhook event', [
                'order_id' => $order->get_id(),
                'event_type' => $event_type,
                'error' => $e->getMessage(),
            ]);
            // Returning a 500 asks the sender to retry — appropriate
            // for a transient DB failure or similar.
            return new WP_REST_Response(['error' => 'handler_failed'], 500);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * An event names its payment, and some also name the link the
     * payment came from. The order knows its link from checkout and
     * its payment once one was seen, so try what is stored before
     * asking the API which link the payment belongs to.
     */
    private static function find_order(Settings $settings, string $payment_id, string $link_id): ?WC_Order
    {
        $order = OrderUpdater::find_by_payment_id($payment_id) ?? OrderUpdater::find_by_link_id($link_id);
        if ($order !== null || $payment_id === '' || $link_id !== '') {
            return $order;
        }
        return PaymentLookup::order_for_payment(SdkFactory::from_settings($settings), $settings, $payment_id);
    }

    private static function verify(string $raw_body, string $header, string $secret): bool
    {
        $ts = '';
        $tags = [];
        foreach (explode(',', $header) as $kv) {
            if (!str_contains($kv, '=')) {
                continue;
            }
            [$k, $v] = explode('=', trim($kv), 2);
            if ($k === 't') {
                $ts = $v;
            } elseif ($k === 'v1') {
                $tags[] = $v;
            }
        }
        if ($tags === [] || !ctype_digit($ts)) {
            return false;
        }
        if (abs(time() - (int) $ts) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $ts . '.' . $raw_body, $secret);
        foreach ($tags as $tag) {
            // hash_equals is constant-time — a `===` comparison would leak
            // the expected signature byte-by-byte over a timing channel.
            if (hash_equals($expected, $tag)) {
                return true;
            }
        }
        return false;
    }

    /** The nonce of an endpoint challenge, or '' when the body is anything else. */
    private static function challenge_nonce(mixed $payload): string
    {
        if (!is_array($payload) || ($payload['event_type'] ?? '') !== EventMap::EVENT_CHALLENGE) {
            return '';
        }
        $nonce = $payload['data']['nonce'] ?? '';
        // Only a hex nonce is ever echoed, so the reply cannot carry markup.
        return is_string($nonce) && preg_match('/^[0-9a-f]{16,128}$/', $nonce) === 1 ? $nonce : '';
    }

    /**
     * The challenge is passed by answering 200 with the nonce as the
     * entire body. The REST server would JSON-encode it, quotes
     * included, so the body is written here instead.
     */
    private static function echo_nonce(string $nonce): WP_REST_Response
    {
        add_filter('rest_pre_serve_request', static function () use ($nonce): bool {
            header('Content-Type: text/plain; charset=utf-8');
            echo $nonce;
            return true;
        });
        return new WP_REST_Response(null, 200);
    }

    private static function raw_body(WP_REST_Request $request): string
    {
        // WP_REST_Request::get_body() returns the raw bytes — but only
        // when route registration didn't pre-parse them. We don't
        // declare any 'args' so the body stays untouched, but to be
        // defensive fall back to php://input if get_body() is empty.
        $body = $request->get_body();
        if ($body === '' || $body === null) {
            $body = (string) file_get_contents('php://input');
        }
        return $body;
    }
}
