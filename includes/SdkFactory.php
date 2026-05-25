<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

use TapTap\Pay\Client as SdkClient;
use TapTap\Pay\Options as SdkOptions;

/**
 * Lazily constructs (and per-request-caches) the SDK Client from saved
 * settings. Centralised so the gateway, refunds path, and webhook
 * provisioner all reach for the same instance — repeatedly newing up
 * a Client per call would also drop the keep-alive curl handle each
 * time.
 */
final class SdkFactory
{
    private static ?SdkClient $cached = null;
    /** @var array{key: string, base: string}|null */
    private static ?array $cachedFor = null;

    public static function from_settings(Settings $settings): SdkClient
    {
        if (!$settings->is_configured()) {
            throw new \RuntimeException(
                __('TapTap Pay is not configured: API key and wallet ID are required.', 'taptap-pay')
            );
        }
        $base = $settings->api_url();
        $key = $settings->apiKey;

        if (self::$cached !== null && self::$cachedFor !== null
            && self::$cachedFor['key'] === $key && self::$cachedFor['base'] === $base) {
            return self::$cached;
        }

        self::$cached = new SdkClient(new SdkOptions(
            apiKey: $key,
            baseUrl: $base,
            userAgent: self::user_agent(),
        ));
        self::$cachedFor = ['key' => $key, 'base' => $base];
        return self::$cached;
    }

    /**
     * Build the per-request UA suffix. The SDK already stamps its own
     * `taptap-sdk-php/x.y.z (php/...)`; this appends a plugin tag so
     * TapTap-side logs can attribute calls to a specific WC version.
     */
    private static function user_agent(): string
    {
        $wpVersion = defined('ABSPATH') && function_exists('get_bloginfo')
            ? get_bloginfo('version')
            : 'unknown';
        $wcVersion = defined('WC_VERSION') ? WC_VERSION : 'unknown';
        return sprintf(
            'taptap-woocommerce/%s (wp/%s; wc/%s)',
            TAPTAP_PAY_VERSION,
            $wpVersion,
            $wcVersion
        );
    }

    /** Invalidate the cache; called when settings change mid-request. */
    public static function reset(): void
    {
        self::$cached = null;
        self::$cachedFor = null;
    }
}
