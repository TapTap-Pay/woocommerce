<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

use Common\V1\Money as ProtoMoney;
use Common\V1\PaginationRequestData;
use Programmatic\Payments\V1\GetPaymentRequest;
use Programmatic\Payments\V1\RequestPaymentRequest;
use Programmatic\Refunds\V1\CreateRefundRequest;
use Programmatic\Transactions\V1\ListTransactionsRequest;
use Programmatic\Types\V1\TransactionKind;
use Programmatic\Types\V1\TransactionStatus;
use TapTap\Pay\Connect\Error as ConnectError;
use TapTap\Pay\Errors as SdkErrors;
use TapTap\Pay\Idempotency;
use WC_Order;
use WC_Payment_Gateway;
use WP_Error;

/**
 * WooCommerce payment gateway entry point.
 *
 * Checkout flow:
 *
 *   1. process_payment() calls Payments.RequestPayment with the
 *      order total, metadata tagging the WC order id, and return
 *      URLs that round back to handle_return().
 *   2. We redirect the customer to the configured TapTap hosted
 *      checkout URL (constructed from the payment id).
 *   3. They pay there; TapTap fires the `payment.succeeded` webhook,
 *      which arrives at /wp-json/taptap-pay/v1/webhook.
 *   4. WebhookController verifies the V2 signature and asks
 *      OrderUpdater to flip the order to paid.
 *
 * Customer return is best-effort reconciliation — the webhook is
 * authoritative. If the webhook lost the race we GET the payment
 * directly so the order-received page shows the right state.
 */
final class Gateway extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = Plugin::GATEWAY_ID;
        $this->method_title = __('TapTap Pay', 'taptap-pay');
        $this->method_description = __(
            'Accept card and bank-transfer payments via TapTap Pay. Customers are redirected to the TapTap hosted checkout to complete payment.',
            'taptap-pay'
        );
        $this->has_fields = false;
        $this->icon = TAPTAP_PAY_PLUGIN_URL . 'assets/icon.svg';
        $this->supports = ['products', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', __('TapTap Pay', 'taptap-pay'));
        $this->description = $this->get_option('description', __('Pay securely via TapTap Pay.', 'taptap-pay'));
        $this->enabled = $this->get_option('enabled', 'no');

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );

        // The "return from TapTap" handler hangs off Woo's API
        // endpoint shim — that gives us a clean public URL
        // (/?wc-api=taptap_return) we can hand to the API as
        // success_url/cancel_url.
        add_action('woocommerce_api_taptap_return', [$this, 'handle_return']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable / Disable', 'taptap-pay'),
                'type' => 'checkbox',
                'label' => __('Enable TapTap Pay at checkout', 'taptap-pay'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title shown to customer', 'taptap-pay'),
                'type' => 'text',
                'default' => __('TapTap Pay', 'taptap-pay'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description shown to customer', 'taptap-pay'),
                'type' => 'textarea',
                'default' => __('Pay securely via TapTap Pay.', 'taptap-pay'),
                'desc_tip' => true,
            ],
            'credentials_section' => [
                'title' => __('TapTap credentials', 'taptap-pay'),
                'type' => 'title',
                'description' => sprintf(
                    /* translators: %s: dashboard URL */
                    __('Mint an API key in the <a href="%s" target="_blank">TapTap dashboard</a> and paste it below along with the wallet that should receive payouts.', 'taptap-pay'),
                    esc_url('https://app.taptap.rs/settings/api-keys')
                ),
            ],
            'api_key' => [
                'title' => __('API key', 'taptap-pay'),
                'type' => 'password',
                'description' => __('Starts with sk_live_ (live) or sk_test_ (sandbox).', 'taptap-pay'),
                'desc_tip' => true,
            ],
            'wallet_id' => [
                'title' => __('Target wallet ID', 'taptap-pay'),
                'type' => 'text',
                'description' => __('UUID of the wallet that should receive payouts from this store.', 'taptap-pay'),
                'desc_tip' => true,
            ],
            'advanced_section' => [
                'title' => __('Advanced', 'taptap-pay'),
                'type' => 'title',
                'description' => __('Defaults are fine for most stores. Override only if pointed at a non-production TapTap deployment.', 'taptap-pay'),
            ],
            'base_url' => [
                'title' => __('API base URL', 'taptap-pay'),
                'type' => 'text',
                'default' => '',
                'placeholder' => \TapTap\Pay\Options::DEFAULT_BASE_URL,
                'description' => __('Leave blank for production. Override for staging / on-prem deployments.', 'taptap-pay'),
                'desc_tip' => true,
            ],
            'checkout_base_url' => [
                'title' => __('Hosted checkout base URL', 'taptap-pay'),
                'type' => 'text',
                'default' => 'https://pay.taptap.rs',
                'description' => __('Customers are redirected to this host + /pay/{payment_id}.', 'taptap-pay'),
                'desc_tip' => true,
            ],
            'webhook_section' => [
                'title' => __('Webhook (auto-managed)', 'taptap-pay'),
                'type' => 'title',
                'description' => sprintf(
                    /* translators: %s: webhook URL */
                    __('TapTap delivers payment events to <code>%s</code>. The plugin auto-provisions the subscription on save — no manual setup needed.', 'taptap-pay'),
                    esc_url(rest_url(Plugin::REST_NAMESPACE . '/webhook'))
                ),
            ],
        ];
    }

    public function is_available(): bool
    {
        if ($this->enabled !== 'yes') {
            return false;
        }
        $settings = Plugin::settings();
        return $settings->is_configured();
    }

    /**
     * Build a fresh TapTap Payment for this WC order and hand the
     * customer the hosted-checkout URL.
     *
     * @param int $order_id
     * @return array{result: string, redirect?: string}
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wc_add_notice(__('Order not found.', 'taptap-pay'), 'error');
            return ['result' => 'failure'];
        }

        $settings = Plugin::settings();
        if (!$settings->is_configured()) {
            wc_add_notice(__('TapTap Pay is not configured. Please contact the store owner.', 'taptap-pay'), 'error');
            return ['result' => 'failure'];
        }

        $currency = strtoupper($order->get_currency());
        $amount_minor = Money::to_minor((float) $order->get_total(), $currency);
        $return_url = $this->return_url_for($order);

        try {
            $client = SdkFactory::from_settings($settings);
            $request = (new RequestPaymentRequest())
                ->setIdempotencyKey($this->idempotency_key_for($order))
                ->setTitle(sprintf(
                    /* translators: %s: order number */
                    __('Order #%s', 'taptap-pay'),
                    $order->get_order_number()
                ))
                ->setDescription(sprintf(
                    /* translators: 1: store name, 2: order number */
                    __('%1$s — order %2$s', 'taptap-pay'),
                    get_bloginfo('name'),
                    $order->get_order_number()
                ))
                ->setTotal((new ProtoMoney())
                    ->setAmountMinor($amount_minor)
                    ->setCurrency($currency))
                ->setTargetWalletId($settings->walletId)
                ->setMetadata([
                    'wc_order_id' => (string) $order->get_id(),
                    'wc_order_key' => (string) $order->get_order_key(),
                    'wc_source' => 'taptap-woocommerce/' . TAPTAP_PAY_VERSION,
                ])
                ->setSuccessUrl($return_url)
                ->setCancelUrl($return_url);

            $response = $client->payments->requestPayment($request);
        } catch (ConnectError $e) {
            Logger::error('RequestPayment failed', [
                'order_id' => $order_id,
                'code' => $e->connectCode,
                'message' => $e->getMessage(),
            ]);
            wc_add_notice(sprintf(
                /* translators: %s: error message */
                __('Could not start TapTap checkout: %s', 'taptap-pay'),
                $e->getMessage()
            ), 'error');
            return ['result' => 'failure'];
        }

        $payment = $response->getPayment();
        if ($payment === null || $payment->getId() === '') {
            wc_add_notice(__('TapTap did not return a payment id.', 'taptap-pay'), 'error');
            return ['result' => 'failure'];
        }
        $payment_id = $payment->getId();

        $order->update_meta_data(OrderUpdater::META_PAYMENT_ID, $payment_id);
        $order->update_status('pending', __('Waiting for TapTap Pay confirmation.', 'taptap-pay'));
        $order->save();

        return [
            'result' => 'success',
            'redirect' => $this->hosted_checkout_url($payment_id),
        ];
    }

    /**
     * Process a refund via the SDK's Refunds.CreateRefund. Triggered
     * from the WC order admin "Refund" button.
     *
     * @param int        $order_id
     * @param float|null $amount
     * @param string     $reason
     */
    public function process_refund($order_id, $amount = null, $reason = ''): bool|WP_Error
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return new WP_Error('taptap_refund_no_order', __('Order not found.', 'taptap-pay'));
        }
        $payment_id = (string) $order->get_meta(OrderUpdater::META_PAYMENT_ID, true);
        if ($payment_id === '') {
            return new WP_Error('taptap_refund_no_payment', __('Order has no associated TapTap payment id.', 'taptap-pay'));
        }
        if ($amount === null || $amount <= 0) {
            return new WP_Error('taptap_refund_bad_amount', __('Refund amount must be greater than zero.', 'taptap-pay'));
        }

        $currency = strtoupper($order->get_currency());
        $minor = Money::to_minor((float) $amount, $currency);

        try {
            $client = SdkFactory::from_settings(Plugin::settings());

            // Refunds attach to the funding PayIn transaction, not the
            // Payment itself. List the payment's transactions, pick the
            // SUCCEEDED payin, and refund against that. If there's more
            // than one (split funding), we currently refund the largest
            // — partial-split refund routing is logged as a gap.
            $payinId = $this->find_refundable_payin($client, $payment_id);
            if ($payinId === '') {
                return new WP_Error(
                    'taptap_refund_no_payin',
                    __('No settled TapTap transaction found to refund against.', 'taptap-pay')
                );
            }

            $req = (new CreateRefundRequest())
                ->setTransactionId($payinId)
                ->setAmount((new ProtoMoney())
                    ->setAmountMinor($minor)
                    ->setCurrency($currency))
                ->setIdempotencyKey('wc_refund_' . $order_id . '_' . hash('sha256', (string) $amount . '|' . $reason));
            if ($reason !== '') {
                $req->setReason($reason);
            }
            $client->refunds->createRefund($req);
        } catch (ConnectError $e) {
            Logger::error('CreateRefund failed', [
                'order_id' => $order_id,
                'payment_id' => $payment_id,
                'code' => $e->connectCode,
                'message' => $e->getMessage(),
            ]);
            if (SdkErrors::isFailedPrecondition($e)) {
                return new WP_Error('taptap_refund_state', __('Refund rejected — the payment is in a state that cannot be refunded (already refunded, not settled, etc.).', 'taptap-pay'));
            }
            return new WP_Error('taptap_refund_failed', $e->getMessage());
        }

        $order->add_order_note(sprintf(
            /* translators: 1: formatted refund amount, 2: reason. */
            __('TapTap refund initiated: %1$s. Reason: %2$s', 'taptap-pay'),
            wp_strip_all_tags((string) wc_price($amount, ['currency' => $currency])),
            $reason !== '' ? $reason : __('(none)', 'taptap-pay')
        ));

        // The actual refunded status flip will land via webhook
        // (refund.succeeded / payment.refunded). Returning true tells
        // WC the API call was accepted.
        return true;
    }

    /**
     * Round the customer back from TapTap hosted checkout. Best-effort
     * reconcile (the webhook is authoritative); the visible effect is
     * that the order-received page renders accurate state instead of
     * always showing "pending".
     */
    public function handle_return(): void
    {
        $order_id = absint($_GET['order_id'] ?? 0);
        $order_key = sanitize_text_field((string) ($_GET['key'] ?? ''));
        $order = wc_get_order($order_id);
        if (!$order || !$order->key_is_valid($order_key)) {
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $payment_id = (string) $order->get_meta(OrderUpdater::META_PAYMENT_ID, true);
        if ($payment_id !== '') {
            try {
                $client = SdkFactory::from_settings(Plugin::settings());
                $resp = $client->payments->getPayment(
                    (new GetPaymentRequest())->setPaymentId($payment_id)
                );
                $p = $resp->getPayment();
                if ($p !== null) {
                    // Convert the proto enum to its string form so
                    // OrderUpdater::reconcile_from_payment can branch
                    // on it consistently with what webhooks deliver.
                    $statusName = \Programmatic\Types\V1\PaymentStatus::name($p->getStatus());
                    OrderUpdater::reconcile_from_payment($order, [
                        'id' => $p->getId(),
                        'status' => $statusName,
                    ]);
                }
            } catch (\Throwable $e) {
                Logger::warning('return-path GetPayment failed', [
                    'order_id' => $order_id,
                    'error' => $e->getMessage(),
                ]);
                $order->add_order_note(sprintf(
                    /* translators: %s: error message */
                    __('TapTap status check failed on return: %s', 'taptap-pay'),
                    $e->getMessage()
                ));
            }
        }

        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    private function return_url_for(WC_Order $order): string
    {
        return add_query_arg(
            [
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ],
            \WC()->api_request_url('taptap_return')
        );
    }

    private function hosted_checkout_url(string $payment_id): string
    {
        $base = (string) $this->get_option('checkout_base_url', 'https://pay.taptap.rs');
        $base = rtrim($base, '/');
        return $base . '/pay/' . rawurlencode($payment_id);
    }

    /**
     * Idempotency keys for RequestPayment must be stable across retries
     * for the *same* checkout attempt but unique per attempt. Tying it
     * to the order id + order key gives us "one TapTap payment per WC
     * order attempt"; if the customer cancels and re-checks out, WC
     * mints a new order with a new key so we naturally get a new key.
     */
    private function idempotency_key_for(WC_Order $order): string
    {
        return 'wc_' . $order->get_id() . '_' . $order->get_order_key();
    }

    /**
     * Locate the PayIn transaction we should refund against. Returns
     * empty string when the payment has no settled payin (e.g. the
     * payment is still pending).
     */
    private function find_refundable_payin(\TapTap\Pay\Client $client, string $payment_id): string
    {
        $req = (new ListTransactionsRequest())
            ->setPaymentId($payment_id)
            ->setKind(TransactionKind::TRANSACTION_KIND_PAYIN)
            ->setStatus(TransactionStatus::TRANSACTION_STATUS_SUCCEEDED)
            ->setPagination((new PaginationRequestData())->setPage(1)->setPageSize(50));
        $resp = $client->transactions->listTransactions($req);

        $best = '';
        $bestAmount = -1;
        foreach ($resp->getTransactions() as $tx) {
            $amount = $tx->getAmount();
            $minor = $amount === null ? 0 : (int) $amount->getAmountMinor();
            if ($minor > $bestAmount) {
                $bestAmount = $minor;
                $best = $tx->getId();
            }
        }
        return $best;
    }
}
