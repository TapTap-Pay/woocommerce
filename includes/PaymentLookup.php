<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

use Taptap\Payment\Programmatic\V1\GetPaymentRequest;
use Taptap\Payment\Programmatic\V1\ListPaymentsRequest;
use Taptap\Payment\Programmatic\V1\PaymentState;
use Taptap\Payment\Programmatic\V1\PaymentSummary;
use TapTap\Pay\Client as SdkClient;
use WC_Order;

/**
 * Ties TapTap payments to Woo orders. An order is checked out through
 * a single-use payment link, and TapTap creates the payment only when
 * the customer pays, so the order knows its link first and learns its
 * payment later. The payment names the link it came from; that is the
 * join both lookups below use.
 */
final class PaymentLookup
{
    private const CAUSE_PAYMENT_LINK = 'PAYMENT_LINK';
    private const LINK_ID_PREFIX = 'plink_';

    /** States in which the customer's money was taken. */
    private const PAID_STATES = [
        PaymentState::PAYMENT_STATE_SUCCEEDED,
        PaymentState::PAYMENT_STATE_PARTIALLY_REFUNDED,
        PaymentState::PAYMENT_STATE_REFUNDED,
    ];

    private function __construct()
    {
    }

    /**
     * The payment made through the order's checkout link: the paid
     * one when there is one, otherwise the newest attempt. Null when
     * nobody has tried to pay yet.
     *
     * @throws \TapTap\Pay\Connect\Error on any RPC failure.
     */
    public static function for_order(SdkClient $client, Settings $settings, WC_Order $order): ?PaymentSummary
    {
        $link_id = (string) $order->get_meta(OrderUpdater::META_LINK_ID, true);
        if ($link_id === '') {
            return null;
        }
        // A payment records its link without the wire prefix, and the
        // filter compares what it is given as is.
        $bare = str_starts_with($link_id, self::LINK_ID_PREFIX)
            ? substr($link_id, strlen(self::LINK_ID_PREFIX))
            : $link_id;
        $response = $client->payments->listPayments(
            (new ListPaymentsRequest())
                ->setVendorId($settings->vendorId)
                ->setCauseKind(self::CAUSE_PAYMENT_LINK)
                ->setCauseRef($bare)
        );

        $newest = null;
        foreach ($response->getPayments() as $payment) {
            if (in_array($payment->getState(), self::PAID_STATES, true)) {
                return $payment;
            }
            $newest ??= $payment;
        }
        return $newest;
    }

    /**
     * The order whose checkout link produced the payment. Null when
     * the payment did not come from one of this store's links — the
     * subscription delivers every payment of the vendor, not only
     * this store's.
     *
     * @throws \TapTap\Pay\Connect\Error on any RPC failure.
     */
    public static function order_for_payment(SdkClient $client, Settings $settings, string $payment_id): ?WC_Order
    {
        $payment = $client->payments->getPayment(
            (new GetPaymentRequest())
                ->setVendorId($settings->vendorId)
                ->setPaymentId($payment_id)
        )->getPayment();
        if ($payment === null || $payment->getCauseKind() !== self::CAUSE_PAYMENT_LINK) {
            return null;
        }
        return OrderUpdater::find_by_link_id(self::wire_link_id($payment->getCauseRef()));
    }

    /** The link id as the API returns it on a link and as the order stores it. */
    public static function wire_link_id(string $ref): string
    {
        if ($ref === '' || str_starts_with($ref, self::LINK_ID_PREFIX)) {
            return $ref;
        }
        return self::LINK_ID_PREFIX . $ref;
    }
}
