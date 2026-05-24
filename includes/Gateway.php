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

        // "Connect with TapTap" OAuth code-exchange flow. The merchant
        // clicks "Authorize" on the settings page, the browser bounces
        // through TapTap's consent screen, and returns with a one-shot
        // code that the callback handler exchanges for api_key +
        // wallet_id + webhook_secret.
        add_action('woocommerce_api_taptap_authorize', [$this, 'handle_authorize_redirect']);
        add_action('woocommerce_api_taptap_callback', [$this, 'handle_authorize_callback']);
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
     * Refuse to save with Enabled=yes when credentials are missing.
     *
     * WC's stock {@see WC_Settings_API::process_admin_options()} happily
     * persists whatever the form submitted, so the operator could tick
     * "Enable TapTap Pay at checkout" without filling in API key or
     * wallet id. Admin would then show the gateway as Active in the
     * Payments list while the customer-facing Checkout silently rendered
     * "There are no payment methods available" — a confusing footgun.
     *
     * We let the parent persist first (so all other field edits stick),
     * then re-read the merged option, and if Enabled is on while either
     * credential is empty we flip Enabled back to "no" and surface a
     * WC admin notice explaining why. The WebhookProvisioner hook on
     * the same action runs at priority 100, so it sees the corrected
     * value and skips provisioning.
     *
     * @return bool true when settings were persisted as submitted; false
     *              when we forced Enabled off.
     */
    public function process_admin_options()
    {
        $saved = parent::process_admin_options();
        if (!$saved) {
            return false;
        }

        // Reload from disk; parent::process_admin_options() updates the
        // option but the in-memory $this->settings copy lags one read.
        $this->init_settings();
        $enabled = $this->get_option('enabled') === 'yes';
        if (!$enabled) {
            return $saved;
        }

        $missing = [];
        if (trim((string) $this->get_option('api_key')) === '') {
            $missing[] = __('API key', 'taptap-pay');
        }
        if (trim((string) $this->get_option('wallet_id')) === '') {
            $missing[] = __('Target wallet ID', 'taptap-pay');
        }
        if ($missing === []) {
            return $saved;
        }

        $this->update_option('enabled', 'no');
        \WC_Admin_Settings::add_error(sprintf(
            /* translators: %s: comma-separated list of missing field names */
            __(
                'TapTap Pay was not enabled at checkout because the following required setting(s) are empty: %s. Fill them in and save again.',
                'taptap-pay'
            ),
            implode(', ', $missing)
        ));
        return false;
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

        // Use the checkout_url the API returns (built from SANDBOX_URL /
        // PROD_URL based on the server's MODE). Fall back to local config
        // only if the field is empty (older API version).
        $checkoutUrl = trim((string) $payment->getCheckoutUrl());
        if ($checkoutUrl === '') {
            $checkoutUrl = $this->hosted_checkout_url($payment_id);
        }

        return [
            'result' => 'success',
            'redirect' => $checkoutUrl,
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
        // Matches the hosted-checkout SPA route in the UI app:
        // <Route path="/pay" element={<Pay />} /> reads `payment_id`
        // from the query string. This fallback is only used when the
        // API didn't populate Payment.payment_process_url itself.
        return $base . '/pay?payment_id=' . rawurlencode($payment_id);
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

    // ---- Connect-with-TapTap OAuth flow ----------------------------------

    /**
     * Step 1: redirect the merchant's browser to TapTap's consent page.
     * Reached via /?wc-api=taptap_authorize.
     */
    public function handle_authorize_redirect(): void
    {
        $state = wp_generate_password(32, false);
        set_transient('taptap_oauth_state', $state, 600);

        $settings = Plugin::settings();
        $base = $settings->effective_base_url();
        $callback = \WC()->api_request_url('taptap_callback');

        $url = $base . '/oauth/woocommerce/authorize?' . http_build_query([
            'redirect_uri' => $callback,
            'state' => $state,
        ]);

        wp_redirect($url);
        exit;
    }

    /**
     * Step 3: receive the one-shot code from the consent redirect,
     * exchange it for credentials, save everything.
     * Reached via /?wc-api=taptap_callback&code=…&state=….
     */
    public function handle_authorize_callback(): void
    {
        $code = sanitize_text_field((string) ($_GET['code'] ?? ''));
        $state = sanitize_text_field((string) ($_GET['state'] ?? ''));
        $error = sanitize_text_field((string) ($_GET['error'] ?? ''));
        $stored_state = (string) get_transient('taptap_oauth_state');
        delete_transient('taptap_oauth_state');

        $settings_url = admin_url(
            'admin.php?page=wc-settings&tab=checkout&section=' . Plugin::GATEWAY_ID
        );

        if ($error !== '') {
            \WC_Admin_Settings::add_error(sprintf(
                /* translators: %s: error key */
                __('TapTap authorization was denied: %s.', 'taptap-pay'),
                $error
            ));
            wp_safe_redirect($settings_url);
            exit;
        }

        if ($code === '' || $state === '' || $stored_state === '' || !hash_equals($stored_state, $state)) {
            \WC_Admin_Settings::add_error(
                __('TapTap authorization failed: invalid or expired state. Please try again.', 'taptap-pay')
            );
            wp_safe_redirect($settings_url);
            exit;
        }

        $settings = Plugin::settings();
        $exchange_url = $settings->effective_base_url() . '/oauth/woocommerce/exchange';
        $resp = wp_remote_post($exchange_url, [
            'body' => ['code' => $code],
            'timeout' => 15,
        ]);

        if (is_wp_error($resp)) {
            \WC_Admin_Settings::add_error(sprintf(
                /* translators: %s: error message */
                __('TapTap credential exchange failed: %s', 'taptap-pay'),
                $resp->get_error_message()
            ));
            wp_safe_redirect($settings_url);
            exit;
        }

        $status_code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);

        if ($status_code !== 200 || !is_array($body) || empty($body['api_key'])) {
            $err = $body['error_description'] ?? $body['error'] ?? 'unexpected response';
            \WC_Admin_Settings::add_error(sprintf(
                /* translators: %s: error description */
                __('TapTap credential exchange failed: %s', 'taptap-pay'),
                esc_html((string) $err)
            ));
            wp_safe_redirect($settings_url);
            exit;
        }

        Settings::persist_patch([
            'enabled' => 'yes',
            'api_key' => $body['api_key'],
            'wallet_id' => $body['wallet_id'] ?? '',
            'webhook_secret' => $body['webhook_secret'] ?? '',
            'webhook_id' => $body['webhook_subscription_id'] ?? '',
        ]);

        \WC_Admin_Settings::add_message(
            __('TapTap Pay is now connected and enabled at checkout.', 'taptap-pay')
        );
        wp_safe_redirect($settings_url);
        exit;
    }

    /**
     * Override admin_options to prepend the "Connect with TapTap" button
     * above the standard form when credentials haven't been set yet.
     */
    public function admin_options(): void
    {
        $settings = Plugin::settings();
        if (!$settings->is_configured()) {
            $authorize_url = \WC()->api_request_url('taptap_authorize');
            echo '<div style="margin-bottom:24px;padding:20px;border:1px solid #2271b1;border-radius:8px;background:#f0f6fc;">';
            echo '<h3 style="margin:0 0 8px">' . esc_html__('Connect your TapTap account', 'taptap-pay') . '</h3>';
            echo '<p style="margin:0 0 12px;color:#555;">';
            echo esc_html__('Click the button below to securely connect this store to your TapTap vendor account. You\'ll choose which wallet should receive payouts.', 'taptap-pay');
            echo '</p>';
            echo '<a href="' . esc_url($authorize_url) . '" class="button button-primary button-hero">';
            echo '🔗 ' . esc_html__('Authorize in TapTap', 'taptap-pay');
            echo '</a>';
            echo '</div>';
        } else {
            echo '<div style="margin-bottom:16px;padding:12px 16px;border:1px solid #00a32a;border-radius:6px;background:#edfaef;">';
            echo '<strong>✓ ' . esc_html__('Connected to TapTap', 'taptap-pay') . '</strong>';
            echo ' — ' . esc_html(sprintf(
                /* translators: %s: api key prefix */
                __('API key %s…', 'taptap-pay'),
                substr($settings->apiKey, 0, 24)
            ));
            echo '</div>';
        }
        parent::admin_options();
    }
}
