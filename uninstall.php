<?php
/**
 * Fires when the user deletes the plugin via WP admin (NOT on simple
 * deactivate). Tear down the webhook subscription on the API side and
 * drop our options row so a fresh install starts clean.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

$option_key = 'woocommerce_taptap_pay_settings';
$settings = get_option($option_key, []);

if (is_array($settings)
    && !empty($settings['api_key'])
    && !empty($settings['webhook_id'])
    && class_exists(\TapTap\Pay\Client::class)
) {
    try {
        $mode = $settings['mode'] ?? 'production';
        $baseUrl = $mode === 'sandbox'
            ? 'https://api.usetaptap.dev'
            : 'https://api.usetaptap.com';

        $client = new \TapTap\Pay\Client(new \TapTap\Pay\Options(
            apiKey: (string) $settings['api_key'],
            baseUrl: $baseUrl,
        ));
        $client->webhooks->deleteWebhook(
            (new \Programmatic\Webhooks\V1\DeleteWebhookRequest())
                ->setId((string) $settings['webhook_id'])
        );
    } catch (\Throwable $e) {
        // Best-effort: a network blip during uninstall shouldn't block
        // the local cleanup below. The vendor can prune leftover subs
        // from the dashboard.
    }
}

delete_option($option_key);
