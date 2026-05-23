<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Top-level orchestrator. Owns the lifecycle hooks (activation, REST
 * routes, gateway registration) so the entry-file stays tiny and
 * boot-only.
 */
final class Plugin
{
    public const GATEWAY_ID = 'taptap_pay';
    public const OPTION_KEY = 'woocommerce_taptap_pay_settings';
    public const REST_NAMESPACE = 'taptap-pay/v1';
    public const ACTIVATION_REDIRECT_FLAG = 'taptap_pay_do_activation_redirect';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * Wires every runtime hook the plugin needs. Idempotent — repeated
     * calls add the same callbacks (WP dedupes them internally) and the
     * gateway registration filter no-ops on the second register.
     */
    public function boot(): void
    {
        add_filter('woocommerce_payment_gateways', [$this, 'register_gateway']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_init', [$this, 'maybe_activation_redirect']);
        add_filter(
            'plugin_action_links_' . plugin_basename(TAPTAP_PAY_PLUGIN_FILE),
            [$this, 'add_settings_link']
        );

        // The webhook provisioner watches setting saves and reaches out
        // to the TapTap API to ensure a subscription exists for this
        // store. Hooked on the same action WC uses to persist gateway
        // settings, so the new API key is already on disk when we read it.
        add_action(
            'woocommerce_update_options_payment_gateways_' . self::GATEWAY_ID,
            [WebhookProvisioner::class, 'sync_after_save'],
            100
        );
    }

    /**
     * @param array<int|string, mixed> $gateways
     * @return array<int|string, mixed>
     */
    public function register_gateway(array $gateways): array
    {
        $gateways[] = Gateway::class;
        return $gateways;
    }

    public function register_rest_routes(): void
    {
        register_rest_route(
            self::REST_NAMESPACE,
            '/webhook',
            [
                'methods' => 'POST',
                'callback' => [WebhookController::class, 'handle'],
                // Verification is signature-based; WordPress capability
                // checks would lock out the API. Auth happens inside
                // the handler via HMAC.
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function add_settings_link(array $links): array
    {
        $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=' . self::GATEWAY_ID);
        array_unshift($links, sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html__('Settings', 'taptap-pay')
        ));
        return $links;
    }

    public static function on_activation(): void
    {
        // Capture the intent to send the admin to the settings page on
        // the next admin pageview. Doing the redirect from the
        // activation hook itself breaks bulk activation (WP redirects
        // back to plugins.php after the loop completes).
        set_transient(self::ACTIVATION_REDIRECT_FLAG, true, 60);
    }

    public static function on_deactivation(): void
    {
        // Intentionally no-op: leave settings + webhook subscription in
        // place so a quick deactivate/reactivate doesn't wipe the
        // store's API credentials. Uninstall is the cleanup path —
        // see uninstall.php.
    }

    public function maybe_activation_redirect(): void
    {
        if (!get_transient(self::ACTIVATION_REDIRECT_FLAG)) {
            return;
        }
        delete_transient(self::ACTIVATION_REDIRECT_FLAG);

        // Don't redirect on bulk activation or AJAX calls — only on a
        // single, interactive activate-this-plugin click.
        if (is_network_admin() || isset($_REQUEST['activate-multi']) || wp_doing_ajax()) {
            return;
        }

        wp_safe_redirect(admin_url(
            'admin.php?page=wc-settings&tab=checkout&section=' . self::GATEWAY_ID
        ));
        exit;
    }

    /** Convenience accessor used by every collaborator that needs an SDK client. */
    public static function settings(): Settings
    {
        return Settings::load();
    }
}
