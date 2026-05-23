<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

use Common\V1\PaginationRequestData;
use Programmatic\Types\V1\WebhookEvent;
use Programmatic\Webhooks\V1\CreateWebhookRequest;
use Programmatic\Webhooks\V1\ListWebhooksRequest;
use Programmatic\Webhooks\V1\RotateWebhookSecretRequest;
use TapTap\Pay\Connect\Error as ConnectError;

/**
 * Reaches out to the TapTap API after each settings save to make sure
 * a webhook subscription pointing at this store exists, is active, and
 * we have its secret on file. Replaces the "go set up a webhook in the
 * dashboard" step in the install flow.
 *
 * Decision tree:
 *
 *   - No webhook_id stored AND no existing webhook targets our URL
 *     → CreateWebhook, store id + returned secret.
 *   - Webhook exists upstream (matches our URL) but we have no secret
 *     locally (fresh install, key was rotated, ...)
 *     → RotateWebhookSecret, store the new secret.
 *   - Webhook id stored AND it's still healthy upstream → no-op.
 *
 * Failures here are reported via WC admin notices but never block the
 * settings save — the store is still usable, just without push events
 * (the return path's GetPayment poll still works).
 */
final class WebhookProvisioner
{
    /** Events we want delivered. Keep in sync with EventMap. */
    private const SUBSCRIBED_EVENTS = [
        WebhookEvent::WEBHOOK_EVENT_PAYMENT_SUCCEEDED,
        WebhookEvent::WEBHOOK_EVENT_PAYMENT_FAILED,
        WebhookEvent::WEBHOOK_EVENT_PAYMENT_CANCELLED,
        WebhookEvent::WEBHOOK_EVENT_PAYMENT_REFUNDED,
        WebhookEvent::WEBHOOK_EVENT_PAYMENT_PARTIALLY_REFUNDED,
    ];

    public static function sync_after_save(): void
    {
        // The Gateway's process_admin_options call has already
        // written to the options table; reload from disk to see the
        // fresh credentials.
        SdkFactory::reset();
        $settings = Settings::load();
        if (!$settings->is_configured()) {
            return;
        }

        try {
            self::ensure_subscription($settings);
        } catch (\Throwable $e) {
            Logger::error('webhook auto-provisioning failed', [
                'error' => $e->getMessage(),
            ]);
            // WC settings page shows admin notices via this transient.
            // We deliberately don't `throw` — settings still save.
            \WC_Admin_Settings::add_error(sprintf(
                /* translators: %s: error message */
                __('TapTap Pay: could not auto-provision the webhook subscription (%s). Payment events may not arrive until you fix and re-save.', 'taptap-pay'),
                $e->getMessage()
            ));
        }
    }

    /**
     * @throws ConnectError on any RPC failure (caller catches).
     */
    private static function ensure_subscription(Settings $settings): void
    {
        $client = SdkFactory::from_settings($settings);
        $target_url = rest_url(Plugin::REST_NAMESPACE . '/webhook');

        $existing = self::find_subscription_for_url($client, $target_url);

        if ($existing === null) {
            $req = (new CreateWebhookRequest())
                ->setUrl($target_url)
                ->setEventTypes(self::SUBSCRIBED_EVENTS)
                ->setDescription(self::description());
            $resp = $client->webhooks->createWebhook($req);
            $sub = $resp->getWebhook();
            if ($sub === null) {
                throw new \RuntimeException('CreateWebhook returned empty subscription');
            }
            Settings::persist_patch([
                'webhook_id' => $sub->getId(),
                'webhook_secret' => $resp->getSecret(),
            ]);
            Logger::info('webhook subscription created', ['id' => $sub->getId()]);
            return;
        }

        // We found an upstream subscription targeting our URL. If we
        // don't have a secret on file, rotate so we have one — there's
        // no API to read the existing secret back (intentional, by the
        // server design).
        if ($settings->webhookSecret === '' || $settings->webhookId !== $existing->getId()) {
            $rotate = $client->webhooks->rotateWebhookSecret(
                (new RotateWebhookSecretRequest())->setId($existing->getId())
            );
            Settings::persist_patch([
                'webhook_id' => $existing->getId(),
                'webhook_secret' => $rotate->getSecret(),
            ]);
            Logger::info('webhook secret rotated', ['id' => $existing->getId()]);
            return;
        }

        Logger::debug('webhook already provisioned', ['id' => $existing->getId()]);
    }

    /**
     * Walks the vendor's webhook list looking for one whose URL matches
     * ours. Linear scan — the cap on subscriptions per vendor is small
     * (think 5–10), so a single page covers it.
     */
    private static function find_subscription_for_url(\TapTap\Pay\Client $client, string $target_url): ?\Programmatic\Types\V1\WebhookSubscription
    {
        $req = (new ListWebhooksRequest())
            ->setPagination((new PaginationRequestData())->setPage(1)->setPageSize(100));
        $resp = $client->webhooks->listWebhooks($req);
        foreach ($resp->getWebhooks() as $sub) {
            if (rtrim($sub->getUrl(), '/') === rtrim($target_url, '/')) {
                return $sub;
            }
        }
        return null;
    }

    private static function description(): string
    {
        return sprintf(
            'WooCommerce — %s (taptap-woocommerce/%s)',
            (string) get_bloginfo('name'),
            TAPTAP_PAY_VERSION
        );
    }
}
