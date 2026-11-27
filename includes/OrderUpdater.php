<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

use WC_Order;

/**
 * Applies an upstream state change to a Woo order. Re-used by the
 * webhook handler AND the customer-return reconciliation path so the
 * "what does state X mean for the order" logic lives in exactly one
 * place.
 *
 * Every transition is idempotent — webhooks are at-least-once, and we
 * also hit this path from the customer return URL, so the same event
 * arriving twice must not move the order forward twice.
 */
final class OrderUpdater
{
    public const META_PAYMENT_ID = '_taptap_payment_id';
    public const META_LINK_ID = '_taptap_payment_link_id';
    public const META_LAST_EVENT = '_taptap_last_event';
    public const META_PROCESSED_EVENTS = '_taptap_processed_events';

    /**
     * Apply a TapTap webhook event to the order. The payload is the
     * delivery envelope:
     *
     *   {
     *     "event_id":   "evt_...",
     *     "event_type": "payment.succeeded",
     *     "data":       { "payment_id": "pay_...", "amount_minor": "1050", "currency": "EUR", ... }
     *   }
     */
    public static function apply_event(WC_Order $order, string $event_type, array $payload): void
    {
        $event_id = (string) ($payload['event_id'] ?? '');
        if ($event_id !== '' && self::already_processed($order, $event_id)) {
            Logger::debug("event already processed, skipping", [
                'order_id' => $order->get_id(),
                'event_id' => $event_id,
            ]);
            return;
        }

        self::remember_payment($order, $event_type, (string) ($payload['data']['payment_id'] ?? ''));

        if (in_array($event_type, EventMap::EVENTS_PAID, true)) {
            self::mark_paid($order, $event_type);
        } elseif (in_array($event_type, EventMap::EVENTS_FAILED, true)) {
            self::mark_failed($order, $event_type, $payload);
        } elseif (in_array($event_type, EventMap::EVENTS_CANCELLED, true)) {
            self::mark_cancelled($order, $event_type);
        } elseif (in_array($event_type, EventMap::EVENTS_REFUNDED, true)) {
            self::mark_refunded($order, $event_type, $payload);
        } elseif (in_array($event_type, EventMap::EVENTS_PARTIALLY_REFUNDED, true)) {
            self::note_partial_refund($order, $payload);
        } else {
            // Known-to-EventMap but unmapped here, OR unknown event.
            // Drop a note so the admin can see we received and ignored it.
            $order->add_order_note(sprintf(
                /* translators: %s: TapTap webhook event type. */
                __('TapTap event received but unhandled: %s', 'taptap-pay'),
                $event_type
            ));
        }

        if ($event_id !== '') {
            self::record_processed($order, $event_id);
        }
        $order->update_meta_data(self::META_LAST_EVENT, $event_type);
        $order->save();
    }

    /**
     * Reconcile an order against a freshly-fetched payment. Used on
     * the customer return path where we don't have an event envelope,
     * only the payment's id and its current PaymentState name.
     */
    public static function reconcile_from_payment(WC_Order $order, string $payment_id, string $state): void
    {
        $synthetic_event = match ($state) {
            // A partly refunded payment was paid first.
            EventMap::STATUS_SUCCEEDED, EventMap::STATUS_PARTIALLY_REFUNDED => 'payment.succeeded',
            EventMap::STATUS_FAILED => 'payment.failed',
            EventMap::STATUS_CANCELLED => 'payment.cancelled',
            EventMap::STATUS_REFUNDED => 'payment.refunded',
            default => '',
        };
        if ($synthetic_event === '') {
            // PENDING / PENDING_APPROVAL / AUTHORIZED — leave the order in its current state.
            return;
        }
        self::apply_event($order, $synthetic_event, ['data' => ['payment_id' => $payment_id]]);
    }

    /**
     * A checkout link can see several attempts. The order keeps the
     * id of the one that was paid, because that is the payment a
     * refund has to name; until then it keeps the first one it saw.
     */
    private static function remember_payment(WC_Order $order, string $event_type, string $payment_id): void
    {
        if ($payment_id === '') {
            return;
        }
        $known = (string) $order->get_meta(self::META_PAYMENT_ID, true);
        if ($known === '' || in_array($event_type, EventMap::EVENTS_PAID, true)) {
            $order->update_meta_data(self::META_PAYMENT_ID, $payment_id);
        }
    }

    private static function mark_paid(WC_Order $order, string $event): void
    {
        if ($order->is_paid()) {
            return;
        }
        $order->payment_complete();
        $order->add_order_note(sprintf(
            /* translators: %s: TapTap event name. */
            __('TapTap %s — order marked paid.', 'taptap-pay'),
            $event
        ));
    }

    private static function mark_failed(WC_Order $order, string $event, array $payload): void
    {
        if ($order->get_status() === 'failed' || $order->is_paid()) {
            return;
        }
        $reason = (string) ($payload['data']['decline_reason'] ?? '');
        $note = sprintf(
            /* translators: 1: TapTap event name, 2: failure reason. */
            __('TapTap %1$s. %2$s', 'taptap-pay'),
            $event,
            $reason
        );
        $order->update_status('failed', trim($note));
    }

    private static function mark_cancelled(WC_Order $order, string $event): void
    {
        if (in_array($order->get_status(), ['cancelled', 'refunded'], true) || $order->is_paid()) {
            return;
        }
        $order->update_status('cancelled', sprintf(
            /* translators: %s: TapTap event name. */
            __('TapTap %s — payment cancelled.', 'taptap-pay'),
            $event
        ));
    }

    private static function mark_refunded(WC_Order $order, string $event, array $payload): void
    {
        if ($order->get_status() === 'refunded') {
            return;
        }
        $order->update_status('refunded', sprintf(
            /* translators: %s: TapTap event name. */
            __('TapTap %s — full refund.', 'taptap-pay'),
            $event
        ));
    }

    private static function note_partial_refund(WC_Order $order, array $payload): void
    {
        $amount = (string) ($payload['data']['amount_minor'] ?? '');
        $currency = (string) ($payload['data']['currency'] ?? $order->get_currency());
        if ($amount !== '' && ctype_digit($amount)) {
            $human = wc_price(Money::from_minor((int) $amount, $currency), ['currency' => $currency]);
            $note = sprintf(
                /* translators: %s: refund amount. */
                __('TapTap partial refund landed: %s', 'taptap-pay'),
                wp_strip_all_tags((string) $human)
            );
        } else {
            $note = __('TapTap partial refund landed.', 'taptap-pay');
        }
        $order->add_order_note($note);
    }

    private static function already_processed(WC_Order $order, string $event_id): bool
    {
        $raw = $order->get_meta(self::META_PROCESSED_EVENTS, true);
        $list = is_array($raw) ? $raw : [];
        return in_array($event_id, $list, true);
    }

    private static function record_processed(WC_Order $order, string $event_id): void
    {
        $raw = $order->get_meta(self::META_PROCESSED_EVENTS, true);
        $list = is_array($raw) ? $raw : [];
        $list[] = $event_id;
        // Cap at the last 50 — orders rarely see more events than this,
        // and unbounded meta growth is a footgun for HPOS migrations.
        if (count($list) > 50) {
            $list = array_slice($list, -50);
        }
        $order->update_meta_data(self::META_PROCESSED_EVENTS, $list);
    }

    /**
     * Reverse-lookup: find the order that owns the given TapTap payment
     * id. Used by the webhook handler. Returns null on miss — webhook
     * handler logs and 200s either way (returning 4xx would trigger
     * retry storms for orders that genuinely belong elsewhere).
     */
    public static function find_by_payment_id(string $payment_id): ?WC_Order
    {
        return self::find_by_meta(self::META_PAYMENT_ID, $payment_id);
    }

    /** Find the order whose checkout went through the given payment link (`plink_…`). */
    public static function find_by_link_id(string $link_id): ?WC_Order
    {
        return self::find_by_meta(self::META_LINK_ID, $link_id);
    }

    private static function find_by_meta(string $key, string $value): ?WC_Order
    {
        if ($value === '') {
            return null;
        }
        $orders = wc_get_orders([
            'limit' => 1,
            'meta_query' => [
                [
                    'key' => $key,
                    'value' => $value,
                ],
            ],
        ]);
        return $orders[0] ?? null;
    }
}
