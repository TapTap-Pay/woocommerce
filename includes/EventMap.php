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
 *   - X-Webhook-Event header: dotted lower_snake (e.g. payment.succeeded)
 *   - PaymentStatus enum:     PAYMENT_STATUS_* screaming snake
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
        'payin.succeeded',
    ];

    public const EVENTS_FAILED = [
        'payment.failed',
        'payin.failed',
    ];

    public const EVENTS_CANCELLED = [
        'payment.cancelled',
    ];

    public const EVENTS_REFUNDED = [
        'payment.refunded',
        'refund.succeeded',
    ];

    public const EVENTS_PARTIALLY_REFUNDED = [
        'payment.partially_refunded',
    ];

    /** PaymentStatus enum strings, mirrored from programmatic.types.v1.PaymentStatus. */
    public const STATUS_PENDING = 'PAYMENT_STATUS_PENDING';
    public const STATUS_IN_PROGRESS = 'PAYMENT_STATUS_IN_PROGRESS';
    public const STATUS_SUCCEEDED = 'PAYMENT_STATUS_SUCCEEDED';
    public const STATUS_FAILED = 'PAYMENT_STATUS_FAILED';
    public const STATUS_CANCELLED = 'PAYMENT_STATUS_CANCELLED';
    public const STATUS_REFUNDED = 'PAYMENT_STATUS_REFUNDED';

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
