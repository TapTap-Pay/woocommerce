<?php

declare(strict_types=1);

namespace TapTap\Pay\WooCommerce;

defined('ABSPATH') || exit;

use Taptap\Common\V1\Money as ProtoMoney;
use Taptap\Payment\Programmatic\V1\CreatePaymentLinkRequest;
use Taptap\Payment\Programmatic\V1\PaymentLink;
use Taptap\Payment\Programmatic\V1\PaymentLinkUsage;
use Taptap\Payment\Programmatic\V1\PaymentState;
use Taptap\Payment\Programmatic\V1\RefundPaymentRequest;
use Taptap\Payment\Programmatic\V1\RefundReason;
use TapTap\Pay\Client as SdkClient;
use TapTap\Pay\Connect\Error as ConnectError;
use TapTap\Pay\Errors as SdkErrors;
use WC_Order;
use WC_Payment_Gateway;
use WP_Error;

final class Gateway extends WC_Payment_Gateway
{
    /**
     * TapTap only redirects a payer to a verified domain of the vendor,
     * over https. Either refusal means this store's return address is
     * not one it will send customers to.
     */
    private const REDIRECT_REFUSALS = [
        'PAYMENT_LINK_REDIRECT_NOT_ALLOWED',
        'PAYMENT_LINK_REDIRECT_SCHEME_INVALID',
    ];

    private const NOTICES_TRANSIENT = 'taptap_pay_connect_notices';

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
                'vendor_id' => '',
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
            $request = (new CreatePaymentLinkRequest())
                ->setVendorId($settings->vendorId)
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
                ->setAmount((new ProtoMoney())
                    ->setAmountMinor($amount_minor)
                    ->setCurrency($currency))
                // One order, one payment: the link stops working once it is paid.
                ->setUsage((new PaymentLinkUsage())->setLimited(1))
                ->setSuccessUrl($return_url)
                ->setCancelUrl($return_url);

            $link = $this->create_checkout_link($client, $request, $order);
        } catch (ConnectError $e) {
            Logger::error('CreatePaymentLink failed', [
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

        if ($link === null || $link->getId() === '' || $link->getPublicUrl() === '') {
            wc_add_notice(__('TapTap did not return a checkout address.', 'taptap-pay'), 'error');
            return ['result' => 'failure'];
        }

        $order->update_meta_data(OrderUpdater::META_LINK_ID, $link->getId());
        $order->update_status('pending', __('Waiting for TapTap Pay confirmation.', 'taptap-pay'));
        $order->save();

        return [
            'result' => 'success',
            'redirect' => $link->getPublicUrl(),
        ];
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|WP_Error
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return new WP_Error('taptap_refund_no_order', __('Order not found.', 'taptap-pay'));
        }
        if ($amount === null || $amount <= 0) {
            return new WP_Error('taptap_refund_bad_amount', __('Refund amount must be greater than zero.', 'taptap-pay'));
        }

        $currency = strtoupper($order->get_currency());
        $minor = Money::to_minor((float) $amount, $currency);

        $payment_id = (string) $order->get_meta(OrderUpdater::META_PAYMENT_ID, true);
        try {
            $settings = Plugin::settings();
            $client = SdkFactory::from_settings($settings);
            if ($payment_id === '') {
                // No webhook and no customer return told the order which
                // payment its link produced, so ask.
                $payment_id = (string) PaymentLookup::for_order($client, $settings, $order)?->getPaymentId();
                if ($payment_id === '') {
                    return new WP_Error('taptap_refund_no_payment', __('Order has no associated TapTap payment id.', 'taptap-pay'));
                }
                $order->update_meta_data(OrderUpdater::META_PAYMENT_ID, $payment_id);
                $order->save();
            }

            $client->refunds->refundPayment(
                (new RefundPaymentRequest())
                    ->setVendorId($settings->vendorId)
                    ->setPaymentId($payment_id)
                    ->setAmount((new ProtoMoney())
                        ->setAmountMinor($minor)
                        ->setCurrency($currency))
                    // The merchant's reason is free text and the API takes
                    // a closed set; the text stays on the order note below.
                    ->setReason(RefundReason::REFUND_REASON_OTHER)
                    ->setRequestKey('wc_refund_' . $order_id . '_' . hash('sha256', (string) $amount . '|' . $reason))
            );
        } catch (ConnectError $e) {
            Logger::error('RefundPayment failed', [
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

        try {
            $settings = Plugin::settings();
            $payment = PaymentLookup::for_order(SdkFactory::from_settings($settings), $settings, $order);
            if ($payment !== null) {
                OrderUpdater::reconcile_from_payment(
                    $order,
                    $payment->getPaymentId(),
                    (string) PaymentState::name($payment->getState())
                );
            }
        } catch (\Throwable $e) {
            Logger::warning('return-path payment lookup failed', [
                'order_id' => $order_id,
                'error' => $e->getMessage(),
            ]);
            $order->add_order_note(sprintf(
                __('TapTap status check failed on return: %s', 'taptap-pay'),
                $e->getMessage()
            ));
        }

        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    // ---- Connect-with-TapTap flow -------------------------------------------

    public function handle_authorize_redirect(): void
    {
        $this->require_store_manager();

        try {
            $url = Connection::begin(Plugin::settings(), \WC()->api_request_url('taptap_callback'));
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            wp_safe_redirect($this->settings_url());
            exit;
        }

        wp_redirect($url);
        exit;
    }

    public function handle_authorize_callback(): void
    {
        $this->require_store_manager();

        $state = sanitize_text_field((string) ($_GET['state'] ?? ''));
        $code = sanitize_text_field((string) ($_GET['code'] ?? ''));
        $error = sanitize_text_field((string) ($_GET['error'] ?? ''));

        $verifier = Connection::take_verifier($state);
        if ($verifier === null) {
            $this->flash(
                'error',
                __('TapTap authorization failed: invalid or expired state. Please try again.', 'taptap-pay')
            );
        } elseif ($error !== '') {
            $this->flash('error', sprintf(
                __('TapTap authorization was denied: %s.', 'taptap-pay'),
                $error
            ));
        } elseif ($code === '') {
            $this->flash(
                'error',
                __('TapTap authorization failed: no credentials received.', 'taptap-pay')
            );
        } else {
            $this->finish_connection($code, $verifier);
        }

        wp_safe_redirect($this->settings_url());
        exit;
    }

    private function finish_connection(string $code, string $verifier): void
    {
        try {
            $credentials = Connection::exchange(Plugin::settings(), $code, $verifier);
        } catch (\RuntimeException $e) {
            Logger::error('install code exchange failed', ['error' => $e->getMessage()]);
            $this->flash('error', sprintf(
                __('TapTap authorization failed: the store could not collect its credentials (%s). Please try again.', 'taptap-pay'),
                $e->getMessage()
            ));
            return;
        }

        Settings::persist_patch([
            'enabled' => 'yes',
            'api_key' => $credentials['api_key'],
            'vendor_id' => $credentials['vendor_id'],
            'webhook_secret' => $credentials['webhook_secret'],
            'webhook_id' => $credentials['webhook_id'],
        ]);
        SdkFactory::reset();

        $this->flash(
            'success',
            __('TapTap Pay is now connected and enabled at checkout.', 'taptap-pay')
        );
        if (!$credentials['webhook_verified']) {
            $this->flash(
                'error',
                __('TapTap could not verify this store\'s webhook address, so payment updates will not arrive yet. Re-send the verification from the webhook settings in your TapTap dashboard.', 'taptap-pay')
            );
        }
    }

    /**
     * Both connect endpoints are public URLs. Without this check anyone
     * could start a handshake and finish it with their own TapTap
     * account, pointing the store's payments at themselves.
     */
    private function require_store_manager(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(
                esc_html__('You are not allowed to connect this store to TapTap.', 'taptap-pay'),
                '',
                ['response' => 403]
            );
        }
    }

    private function settings_url(): string
    {
        return admin_url('admin.php?page=wc-settings&tab=checkout&section=' . Plugin::GATEWAY_ID);
    }

    /**
     * The connect endpoints answer with a redirect, so what they have
     * to tell the merchant must outlive the request.
     */
    private function flash(string $type, string $message): void
    {
        $notices = get_transient(self::NOTICES_TRANSIENT);
        $notices = is_array($notices) ? $notices : [];
        $notices[] = ['type' => $type, 'message' => $message];
        set_transient(self::NOTICES_TRANSIENT, $notices, 300);
    }

    private function render_flashed_notices(): void
    {
        $notices = get_transient(self::NOTICES_TRANSIENT);
        if (!is_array($notices)) {
            return;
        }
        delete_transient(self::NOTICES_TRANSIENT);
        foreach ($notices as $notice) {
            printf(
                '<div class="notice notice-%s inline"><p>%s</p></div>',
                ($notice['type'] ?? '') === 'error' ? 'error' : 'success',
                esc_html((string) ($notice['message'] ?? ''))
            );
        }
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

        $this->render_flashed_notices();

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
            echo esc_html__('Click the button below to securely connect this store to your TapTap vendor account. You\'ll approve the connection in your TapTap dashboard.', 'taptap-pay');
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

    private function idempotency_key_for(WC_Order $order): string
    {
        return 'wc_' . $order->get_id() . '_' . $order->get_order_key();
    }

    /**
     * Creates the order's checkout link. When TapTap refuses the
     * store's return address the link is created without one: the
     * customer then stays on TapTap's confirmation page after paying
     * and the order is completed by the webhook alone, which beats
     * taking no payments until the domain is verified.
     *
     * @throws ConnectError on any other RPC failure.
     */
    private function create_checkout_link(SdkClient $client, CreatePaymentLinkRequest $request, WC_Order $order): ?PaymentLink
    {
        try {
            return $client->paymentLinks->createPaymentLink($request)->getPaymentLink();
        } catch (ConnectError $e) {
            if (!in_array(SdkErrors::errorCode($e), self::REDIRECT_REFUSALS, true)) {
                throw $e;
            }
        }

        Logger::warning('TapTap refused the store return address; checkout continues without it', [
            'order_id' => $order->get_id(),
        ]);
        $order->add_order_note(
            __('TapTap will not send the customer back to this store after payment, because the store\'s domain is not a verified domain of your TapTap account (or the store is not served over https). Verify the domain in your TapTap dashboard to fix this. The order is still updated when the payment completes.', 'taptap-pay')
        );
        // The key covers the request's content, and this is a different request.
        $request
            ->setSuccessUrl('')
            ->setCancelUrl('')
            ->setIdempotencyKey($request->getIdempotencyKey() . '_noreturn');
        return $client->paymentLinks->createPaymentLink($request)->getPaymentLink();
    }
}
