=== TapTap Pay for WooCommerce ===
Contributors: taptappay
Tags: woocommerce, payments, gateway, taptap, taptap-pay
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.0.48
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Official TapTap-Pay payment gateway for WooCommerce. Self-updating from GitHub releases.

== Description ==

TapTap Pay for WooCommerce adds TapTap-Pay as a checkout option on your WooCommerce store. Customers are redirected to the TapTap-hosted checkout, pay with card or bank transfer, and bounce back to your store with the order marked paid.

**Features:**

* Hosted checkout — no card data ever touches your store.
* Auto-provisioned webhook on save: paste your API key and the plugin sets up the rest.
* HMAC-SHA256 V2 signature verification on every webhook delivery.
* Refunds from the WooCommerce order admin.
* Self-update from GitHub releases — no manual zip uploads after the first install.
* Compatible with WooCommerce High-Performance Order Storage (HPOS).

== Installation ==

1. Download the latest `taptap-pay-*.zip` from [GitHub releases](https://github.com/TapTap-Pay/woocommerce/releases).
2. WP Admin → Plugins → Add New → Upload Plugin → choose the zip → Install Now → Activate.
3. You will be redirected to the gateway settings page. Paste your TapTap API key (from app.taptap.rs → Settings → API Keys) and the wallet UUID that should receive payouts.
4. Click Save Changes. The plugin will auto-create a webhook subscription pointing at your store.
5. Tick "Enable TapTap Pay at checkout" and save again.

That's it — place a test order to confirm.

== Frequently Asked Questions ==

= Where do I get an API key? =

Sign up at https://app.taptap.rs and mint a key under Settings → API Keys. Sandbox keys are prefixed `sk_test_`; live keys `sk_live_`.

= Does the plugin auto-update? =

Yes. WP checks the [GitHub releases](https://github.com/TapTap-Pay/woocommerce/releases) feed and offers updates from the standard WP Admin → Plugins screen. Enable auto-update there if you want them applied without intervention.

= How are webhooks verified? =

Each delivery carries `X-Webhook-Signature-V2: t=<unix>,v1=<hex>`. The plugin recomputes HMAC-SHA256 over `<timestamp>.<raw-body>` with your subscription secret and rejects mismatches and stale (>5min) timestamps.

= Where do I see plugin logs? =

WooCommerce → Status → Logs → source `taptap-pay`.

== Changelog ==

= 0.1.0 =

* Initial release. Hosted-checkout flow, auto-provisioned webhooks, V2 signature verification, refunds, GitHub-releases auto-update.

== Upgrade Notice ==

= 0.1.0 =
First release.
