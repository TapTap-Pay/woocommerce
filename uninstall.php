<?php
/**
 * Fires when the user deletes the plugin via WP admin (NOT on simple
 * deactivate). Drops our options row so a fresh install starts clean.
 *
 * The store's API key may take payments and nothing else, so it cannot
 * remove the integration TapTap created for this store. The merchant
 * revokes it under Integrations in the TapTap dashboard, which also
 * revokes the key and the webhook subscription.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('woocommerce_taptap_pay_settings');
delete_transient('taptap_pay_connection');
delete_transient('taptap_pay_connect_notices');
