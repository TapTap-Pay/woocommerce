<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * WooCommerce Blocks (modern Checkout block) integration.
 *
 * The legacy {@see Gateway} class only registers via the
 * `woocommerce_payment_gateways` filter, which the *shortcode-based*
 * checkout uses. Stores running the block-based Checkout (default
 * since WC 8.3 and the new template since 9.0) iterate a separate
 * payment-method-type registry instead, so a gateway that doesn't
 * register here is invisible at checkout even when "Active" in
 * Payments admin.
 *
 * This class is the bridge: it declares the script handle that
 * registers our payment method on the JS side and forwards the
 * customer-facing title/description from the gateway settings so the
 * client doesn't need to know the option layout.
 */
final class BlocksGateway extends AbstractPaymentMethodType
{
    /** Must match Gateway::GATEWAY_ID and the JS payment method `name`. */
    protected $name = Plugin::GATEWAY_ID;

    public function initialize(): void
    {
        // No-op: Settings::load() reads from the same option WC writes
        // when the merchant saves the gateway form, so we don't need to
        // duplicate option-key bookkeeping here.
    }

    /**
     * Whether the block-checkout should offer this gateway at all.
     * Mirrors Gateway::is_available() so admin and customer views
     * agree.
     */
    public function is_active(): bool
    {
        $settings = Settings::load();
        return $settings->enabled && $settings->is_configured();
    }

    /**
     * Script handle(s) that register the payment method in the
     * `wc.wcBlocksRegistry` on the client. The handle must already be
     * registered with `wp_register_script` — we do that here so the
     * caller (WC Blocks core) just sees a handle it can enqueue.
     *
     * @return string[]
     */
    public function get_payment_method_script_handles(): array
    {
        $handle = 'taptap-pay-blocks';
        $src = TAPTAP_PAY_PLUGIN_URL . 'assets/js/blocks-payment-method.js';

        $asset_file = TAPTAP_PAY_PLUGIN_DIR . 'assets/js/blocks-payment-method.asset.php';
        $deps = ['wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n'];
        $version = TAPTAP_PAY_VERSION;
        if (file_exists($asset_file)) {
            /** @var array{dependencies?: string[], version?: string} $asset */
            $asset = require $asset_file;
            $deps = $asset['dependencies'] ?? $deps;
            $version = $asset['version'] ?? $version;
        }

        wp_register_script($handle, $src, $deps, $version, true);

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'taptap-pay');
        }

        return [$handle];
    }

    /**
     * Server-supplied data the JS payment method reads via
     * `getPaymentMethodData('taptap_pay')`. Kept to the bare minimum
     * needed for the label + description shown at checkout.
     *
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array
    {
        $settings = Settings::load();
        return [
            'title' => $settings->title,
            'description' => $settings->description,
            'icon' => TAPTAP_PAY_PLUGIN_URL . 'assets/icon.svg',
            'supports' => ['products', 'refunds'],
        ];
    }
}
