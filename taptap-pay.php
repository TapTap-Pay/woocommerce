<?php
/**
 * Plugin Name:       TapTap Pay for WooCommerce
 * Plugin URI:        https://github.com/TapTap-Pay/woocommerce
 * Description:       Accept card and bank-transfer payments via TapTap-Pay. Self-updating from GitHub releases.
 * Version:           0.0.47
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            TapTap-Pay
 * Author URI:        https://taptap.rs
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       taptap-pay
 * Update URI:        https://github.com/TapTap-Pay/woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   9.5
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (defined('TAPTAP_PAY_PLUGIN_FILE')) {
    return;
}

define('TAPTAP_PAY_PLUGIN_FILE', __FILE__);
define('TAPTAP_PAY_VERSION', '0.0.47');
define('TAPTAP_PAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TAPTAP_PAY_PLUGIN_URL', plugin_dir_url(__FILE__));

$autoload = TAPTAP_PAY_PLUGIN_DIR . 'vendor/autoload.php';
if (!file_exists($autoload)) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p><strong>TapTap Pay:</strong> ';
        echo esc_html__('vendor/autoload.php is missing. Install the plugin from a release zip rather than a git checkout, or run `composer install` in the plugin directory.', 'taptap-pay');
        echo '</p></div>';
    });
    return;
}

require_once $autoload;

// Declare WooCommerce HPOS (High-Performance Order Storage) compatibility.
// Without this the plugin shows an incompatibility warning in stores that
// have HPOS enabled — we don't touch any legacy order-table internals,
// so the gateway works against both storage engines unchanged.
add_action('before_woocommerce_init', function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            TAPTAP_PAY_PLUGIN_FILE,
            true
        );
    }
});

// Wire the update checker as early as possible: WP polls update endpoints
// from cron, which can run before plugins_loaded if a request happens to
// trigger a cron tick. Hooking it here ensures the PUC handlers are
// registered before any update sweep.
\TapTap\Pay\WooCommerce\UpdateChecker::bootstrap(TAPTAP_PAY_PLUGIN_FILE);

// WooCommerce hooks aren't available before `plugins_loaded` — the
// gateway class extends WC_Payment_Gateway which is only defined once
// WooCommerce itself has loaded.
add_action('plugins_loaded', function (): void {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-warning"><p><strong>TapTap Pay:</strong> ';
            echo esc_html__('WooCommerce is required. Install and activate WooCommerce, then re-activate this plugin.', 'taptap-pay');
            echo '</p></div>';
        });
        return;
    }
    \TapTap\Pay\WooCommerce\Plugin::instance()->boot();
});

register_activation_hook(__FILE__, [\TapTap\Pay\WooCommerce\Plugin::class, 'on_activation']);
register_deactivation_hook(__FILE__, [\TapTap\Pay\WooCommerce\Plugin::class, 'on_deactivation']);
