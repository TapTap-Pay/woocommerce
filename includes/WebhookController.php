<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint that receives signed TapTap webhook deliveries.
 *
 * Verification is **HMAC-SHA256 over `<timestamp>.<raw-body>`** with
 * the per-subscription secret, format `t=<unix>,v1=<hex>` in the
 * `X-Webhook-Signature-V2` header. The legacy `X-Webhook-Signature`
 * header is intentionally NOT consulted — the API will remove it
 * 2027-01-01 (see api docs flows/webhooks-signing.md).
 *
 * Two non-obvious choices:
 *
 *   1. We read the raw HTTP body via `php://input`, not the WP REST
 *      parsed payload, because the signature is over the bytes-on-wire
 *      and WP's JSON normalisation would silently alter them.
 *
 *   2. We respond 200 on "couldn't find a matching order" instead of
 *      404. The webhook sender retries on any non-2xx, and a 404 here
 *      is permanent — replaying it forever achieves nothing. The log
 *      line is enough for an operator to investigate.
 */
final class WebhookController
{
    public const TOLERANCE_SECONDS = 300;

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $settings = Plugin::settings();
        if ($settings->webhookSecret === '') {
            Logger::warning('webhook hit but no secret configured');
            return new WP_REST_Response(['error' => 'not_configured'], 503);
        }

        $raw = self::raw_body($request);
        $headers = self::headers($request);

        if (!self::verify($raw, $headers, $settings->webhookSecret)) {
            Logger::warning('webhook signature verification failed', [
                'has_v2_header' => isset($headers['x-webhook-signature-v2']),
                'has_timestamp' => isset($headers['x-webhook-timestamp']),
            ]);
            return new WP_REST_Response(['error' => 'invalid_signature'], 401);
        }

        $event_type = (string) ($headers['x-webhook-event'] ?? '');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            Logger::warning('webhook body is not JSON', ['event_type' => $event_type]);
            return new WP_REST_Response(['error' => 'invalid_json'], 400);
        }

        // Pull the payment id from the most common shapes the API emits.
        $payment_id = (string) (
            $payload['payment']['id']
            ?? $payload['payment_id']
            ?? $payload['data']['payment']['id']
            ?? ''
        );

        if ($payment_id === '') {
            Logger::info('webhook had no payment id, ignoring', ['event_type' => $event_type]);
            return new WP_REST_Response(['ok' => true], 200);
        }

        $order = OrderUpdater::find_by_payment_id($payment_id);
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
     * @param array<string, string> $headers all lower-cased
     */
    private static function verify(string $raw_body, array $headers, string $secret): bool
    {
        $ts = $headers['x-webhook-timestamp'] ?? '';
        $sig_header = $headers['x-webhook-signature-v2'] ?? '';
        if ($ts === '' || $sig_header === '' || !ctype_digit($ts)) {
            return false;
        }
        if (abs(time() - (int) $ts) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $sent = self::extract_v1($sig_header);
        if ($sent === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $ts . '.' . $raw_body, $secret);
        // hash_equals is constant-time — a `===` comparison would leak
        // the expected signature byte-by-byte over a timing channel.
        return hash_equals($expected, $sent);
    }

    /** Parse `t=...,v1=...` and return the v1 segment (case-insensitive). */
    private static function extract_v1(string $header): string
    {
        foreach (explode(',', $header) as $kv) {
            $kv = trim($kv);
            if (!str_contains($kv, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $kv, 2);
            if (strtolower(trim($k)) === 'v1') {
                return trim($v);
            }
        }
        return '';
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

    /** @return array<string, string> all lower-cased keys, single-value */
    private static function headers(WP_REST_Request $request): array
    {
        $out = [];
        foreach ($request->get_headers() as $k => $values) {
            $out[strtolower((string) $k)] = is_array($values) ? (string) reset($values) : (string) $values;
        }
        return $out;
    }
}
