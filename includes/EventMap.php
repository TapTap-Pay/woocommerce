<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Single source of truth for translating TapTap webhook events into
 * Woo order-status transitions.
 *
 * The TapTap API emits two parallel naming schemes:
 *
 *   - event_type in the webhook envelope: dotted lower_snake
 *     (e.g. payment.succeeded)
 *   - PaymentState enum: PAYMENT_STATE_* screaming snake
 *
 * Webhook deliveries always carry the dotted form; the status-poll
 * fallback path (on customer return) reads the enum. Both feed this
 * file so the gateway only knows about "do X to the order".
 */
final class EventMap
{
    /**
     * Webhook events that should mark a Woo order paid. Listed
     * explicitly — pattern-matching on a name prefix would silently
     * absorb a future event we don't yet understand.
     */
    public const EVENTS_PAID = [
        'payment.succeeded',
    ];

    public const EVENTS_FAILED = [
        'payment.failed',
    ];

    public const EVENTS_CANCELLED = [
        'payment.cancelled',
    ];

    public const EVENTS_REFUNDED = [
        'payment.refunded',
    ];

    public const EVENTS_PARTIALLY_REFUNDED = [
        'payment.partially_refunded',
    ];

    /**
     * Sent once when a subscription is created or its challenge is
     * re-issued: the endpoint proves it owns the URL by echoing the
     * nonce in the envelope's data.
     */
    public const EVENT_CHALLENGE = 'webhook.endpoint_challenge';

    /** PaymentState enum strings, mirrored from taptap.payment.programmatic.v1.PaymentState. */
    public const STATUS_SUCCEEDED = 'PAYMENT_STATE_SUCCEEDED';
    public const STATUS_FAILED = 'PAYMENT_STATE_FAILED';
    public const STATUS_CANCELLED = 'PAYMENT_STATE_CANCELLED';
    public const STATUS_PARTIALLY_REFUNDED = 'PAYMENT_STATE_PARTIALLY_REFUNDED';
    public const STATUS_REFUNDED = 'PAYMENT_STATE_REFUNDED';

    private function __construct()
    {
    }

    /** True if the given webhook event is one we should react to. */
    public static function is_known_event(string $event): bool
    {
        return in_array($event, array_merge(
            self::EVENTS_PAID,
            self::EVENTS_FAILED,
            self::EVENTS_CANCELLED,
            self::EVENTS_REFUNDED,
            self::EVENTS_PARTIALLY_REFUNDED,
        ), true);
    }
}
