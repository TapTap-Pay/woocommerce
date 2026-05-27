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

        add_action('woocommerce_api_taptap_return', [$this, 'handle_return']);
        add_action('woocommerce_api_taptap_authorize', [$this, 'handle_authorize_redirect']);
        add_action('woocommerce_api_taptap_callback', [$this, 'handle_authorize_callback']);

        add_action('woocommerce_before_checkout_form', [$this, 'maybe_show_sandbox_banner']);
    }

    // -- Environment URLs ---------------------------------------------------

    public const PROD_API_URL = 'https://usetaptap.com/';
    public const PROD_UI_URL = 'https://dash.usetaptap.com/';
    public const SANDBOX_API_URL = 'https://api.usetaptap.dev/';
    public const SANDBOX_UI_URL = 'https://admin.usetaptap.dev';

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'mode' => [
                'title' => __('Mode', 'taptap-pay'),
                'type' => 'select',
                'default' => 'production',
                'options' => [
                    'production' => __('Production', 'taptap-pay'),
                    'sandbox' => __('Sandbox', 'taptap-pay'),
                ],
                'description' => __('Sandbox uses the test environment. Switch to Production for live payments.', 'taptap-pay'),
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

    public function process_admin_options()
    {
        $old_mode = $this->get_option('mode', 'production');
        $saved = parent::process_admin_options();
        if (!$saved) {
            return false;
        }

        $this->init_settings();
        $new_mode = $this->get_option('mode', 'production');

        if ($old_mode !== $new_mode) {
            Settings::persist_patch([
                'api_key' => '',
                'wallet_id' => '',
                'webhook_secret' => '',
                'webhook_id' => '',
                'enabled' => 'no',
            ]);
            SdkFactory::reset();
            \WC_Admin_Settings::add_message(
                __('Mode changed — TapTap Pay has been disconnected. Click "Authorize in TapTap" to reconnect.', 'taptap-pay')
            );
        }

        return $saved;
    }

    // ---- Checkout -----------------------------------------------------------

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
                    __('Order #%s', 'taptap-pay'),
                    $order->get_order_number()
                ))
                ->setDescription(sprintf(
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

        $checkoutUrl = '';
        if (method_exists($payment, 'getCheckoutUrl')) {
            $checkoutUrl = trim((string) $payment->getCheckoutUrl());
        }
        if ($checkoutUrl === '') {
            $checkoutUrl = $this->hosted_checkout_url($payment_id);
        }

        return [
            'result' => 'success',
            'redirect' => $checkoutUrl,
        ];
    }

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
            __('TapTap refund initiated: %1$s. Reason: %2$s', 'taptap-pay'),
            wp_strip_all_tags((string) wc_price($amount, ['currency' => $currency])),
            $reason !== '' ? $reason : __('(none)', 'taptap-pay')
        ));

        return true;
    }

    // ---- Customer return ----------------------------------------------------

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
                    __('TapTap status check failed on return: %s', 'taptap-pay'),
                    $e->getMessage()
                ));
            }
        }

        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    // ---- Connect-with-TapTap flow -------------------------------------------

    public function handle_authorize_redirect(): void
    {
        $state = wp_generate_password(32, false);
        set_transient('taptap_oauth_state', $state, 600);

        $settings = Plugin::settings();
        $callback = \WC()->api_request_url('taptap_callback');

        $url = $settings->ui_url() . '/authorize/woocommerce?' . http_build_query([
            'redirect_uri' => $callback,
            'state' => $state,
        ]);

        wp_redirect($url);
        exit;
    }

    public function handle_authorize_callback(): void
    {
        $settings_url = admin_url(
            'admin.php?page=wc-settings&tab=checkout&section=' . Plugin::GATEWAY_ID
        );

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handle_callback_post($settings_url);
        } else {
            $this->handle_callback_get($settings_url);
        }
    }

    private function handle_callback_post(string $settings_url): void
    {
        $state = sanitize_text_field((string) ($_POST['state'] ?? ''));
        $api_key = sanitize_text_field((string) ($_POST['api_key'] ?? ''));
        $wallet_id = sanitize_text_field((string) ($_POST['wallet_id'] ?? ''));
        $webhook_secret = sanitize_text_field((string) ($_POST['webhook_secret'] ?? ''));
        $webhook_id = sanitize_text_field((string) ($_POST['webhook_subscription_id'] ?? ''));

        $stored_state = (string) get_transient('taptap_oauth_state');
        delete_transient('taptap_oauth_state');

        if ($state === '' || $stored_state === '' || !hash_equals($stored_state, $state)) {
            \WC_Admin_Settings::add_error(
                __('TapTap authorization failed: invalid or expired state. Please try again.', 'taptap-pay')
            );
            wp_safe_redirect($settings_url);
            exit;
        }

        if ($api_key === '') {
            \WC_Admin_Settings::add_error(
                __('TapTap authorization failed: no credentials received.', 'taptap-pay')
            );
            wp_safe_redirect($settings_url);
            exit;
        }

        Settings::persist_patch([
            'enabled' => 'yes',
            'api_key' => $api_key,
            'wallet_id' => $wallet_id,
            'webhook_secret' => $webhook_secret,
            'webhook_id' => $webhook_id,
        ]);

        \WC_Admin_Settings::add_message(
            __('TapTap Pay is now connected and enabled at checkout.', 'taptap-pay')
        );
        wp_safe_redirect($settings_url);
        exit;
    }

    private function handle_callback_get(string $settings_url): void
    {
        $error = sanitize_text_field((string) ($_GET['error'] ?? ''));
        if ($error !== '') {
            \WC_Admin_Settings::add_error(sprintf(
                __('TapTap authorization was denied: %s.', 'taptap-pay'),
                $error
            ));
        }
        wp_safe_redirect($settings_url);
        exit;
    }

    // ---- Sandbox banner -----------------------------------------------------

    public function maybe_show_sandbox_banner(): void
    {
        static $shown = false;
        if ($shown) {
            return;
        }

        $settings = Plugin::settings();
        if ($settings->mode !== 'sandbox' || !$settings->is_configured()) {
            return;
        }

        $shown = true;
        echo '<div class="woocommerce-info" style="background:#fff3cd;border-color:#ffc107;color:#856404;margin-bottom:20px;padding:12px 16px;">';
        echo '<strong>' . esc_html__('Sandbox Mode', 'taptap-pay') . '</strong> — ';
        echo esc_html__('This store is running in test mode. No real money will be charged.', 'taptap-pay');
        echo '</div>';
    }

    // ---- Admin settings page ------------------------------------------------

    public function admin_options(): void
    {
        $settings = Plugin::settings();
        $mode = $settings->mode;

        if ($settings->is_configured()) {
            echo '<div style="margin-bottom:16px;padding:12px 16px;border:1px solid #00a32a;border-radius:6px;background:#edfaef;">';
            echo '<strong>✓ ' . esc_html__('Connected to TapTap', 'taptap-pay') . '</strong>';
            echo ' — ' . esc_html(sprintf(
                __('API key %s…', 'taptap-pay'),
                substr($settings->apiKey, 0, 24)
            ));
            echo ' <span style="color:#666;">(' . esc_html($mode === 'sandbox' ? 'Sandbox' : 'Production') . ')</span>';
            echo '</div>';
        } else {
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
        }

        if ($mode === 'sandbox') {
            echo '<div style="margin-bottom:16px;padding:12px 16px;border:1px solid #ffc107;border-radius:6px;background:#fff3cd;color:#856404;">';
            echo '<strong>' . esc_html__('Sandbox Mode', 'taptap-pay') . '</strong> — ';
            echo esc_html__('No real money will be charged. Use test credentials to simulate payments.', 'taptap-pay');
            echo '</div>';
        }

        echo '<h3>' . esc_html__('TapTap Pay', 'taptap-pay') . '</h3>';
        echo '<p>' . esc_html($this->method_description) . '</p>';
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';

        $this->render_version_info();
        $this->render_mode_change_script();
    }

    private function render_version_info(): void
    {
        $check_url = wp_nonce_url(
            admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Plugin::GATEWAY_ID . '&taptap_check_update=1'),
            'taptap_check_update'
        );

        if (isset($_GET['taptap_check_update']) && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'taptap_check_update')) {
            $this->trigger_update_check();
        }

        echo '<div style="margin-top:24px;padding:12px 16px;border:1px solid #ddd;border-radius:6px;background:#fafafa;display:flex;align-items:center;justify-content:space-between;">';
        echo '<span style="color:#666;">';
        echo esc_html(sprintf(
            __('TapTap Pay for WooCommerce v%s', 'taptap-pay'),
            TAPTAP_PAY_VERSION
        ));
        echo '</span>';
        echo '<a href="' . esc_url($check_url) . '" class="button button-small">';
        echo esc_html__('Check for updates', 'taptap-pay');
        echo '</a>';
        echo '</div>';
    }

    private function trigger_update_check(): void
    {
        if (!class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class)) {
            \WC_Admin_Settings::add_error(__('Update checker is not available.', 'taptap-pay'));
            return;
        }

        $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/TapTap-Pay/woocommerce/',
            TAPTAP_PAY_PLUGIN_FILE,
            'taptap-pay'
        );
        $checker->getVcsApi()->enableReleaseAssets('/^taptap-pay-.*\.zip$/');

        $update = $checker->checkForUpdates();
        if ($update !== null && version_compare($update->version, TAPTAP_PAY_VERSION, '>')) {
            \WC_Admin_Settings::add_message(sprintf(
                __('A new version is available: v%s. Go to Plugins → Updates to install it.', 'taptap-pay'),
                esc_html($update->version)
            ));
        } else {
            \WC_Admin_Settings::add_message(__('You are running the latest version.', 'taptap-pay'));
        }
    }

    private function render_mode_change_script(): void
    {
        $configured = Plugin::settings()->is_configured();
        if (!$configured) {
            return;
        }
        ?>
        <script>
        (function() {
            var sel = document.getElementById('woocommerce_taptap_pay_mode');
            if (!sel) return;
            var original = sel.value;
            sel.addEventListener('change', function(e) {
                if (sel.value === original) return;
                var ok = confirm(
                    'Switching mode will disconnect your TapTap account. ' +
                    'You will need to re-authorize after saving.\n\nContinue?'
                );
                if (!ok) {
                    sel.value = original;
                    e.preventDefault();
                }
            });
        })();
        </script>
        <?php
    }

    // ---- Helpers -------------------------------------------------------------

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
        return rtrim(Plugin::settings()->ui_url(), '/') . '/pay?payment_id=' . rawurlencode($payment_id);
    }

    private function idempotency_key_for(WC_Order $order): string
    {
        return 'wc_' . $order->get_id() . '_' . $order->get_order_key();
    }

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
