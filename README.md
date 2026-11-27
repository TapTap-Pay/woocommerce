# TapTap Pay for WooCommerce

[![CI](https://github.com/TapTap-Pay/woocommerce/actions/workflows/ci.yml/badge.svg)](https://github.com/TapTap-Pay/woocommerce/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

Official WooCommerce payment gateway for [TapTap-Pay](https://usetaptap.com).

## What you get

- **Hosted checkout** — customers are redirected to TapTap's PCI-scoped checkout host, no card data touches your store.
- **One-click connect** — approve the store in your TapTap dashboard and TapTap creates a scoped API key and a webhook subscription for it. The store collects both server to server; no secret passes through the browser and nothing is pasted.
- **HMAC-SHA256 signature verification** — every webhook delivery is verified against the per-subscription secret (`TapTap-Signature: t=<unix>,v1=<hex>`) with a 5-minute replay window, in constant time.
- **Refunds** — refund from the standard WooCommerce order admin "Refund" button. The refund goes back the way the payment came.
- **Self-updating** — uses [Plugin Update Checker v5](https://github.com/YahnisElsts/plugin-update-checker) against [this repo's GitHub releases](https://github.com/TapTap-Pay/woocommerce/releases). WP polls, downloads, and installs new versions through the standard plugin-update UI.
- **HPOS-compatible** — works against both the legacy WP-post order store and WooCommerce's new high-performance order storage.

## Install

Grab the latest `taptap-pay-<version>.zip` from the [releases page](https://github.com/TapTap-Pay/woocommerce/releases) and upload it via **WP Admin → Plugins → Add New → Upload Plugin**.

After activation you'll be auto-redirected to the gateway settings. Pick **Sandbox** or **Production**, save, then click **Authorize in TapTap** and approve the connection in your TapTap dashboard. You come back to a connected store with TapTap Pay enabled at checkout.

The store has to meet three conditions:

- **https** — TapTap delivers webhooks to `https://<your store>/wp-json/taptap-pay/v1/webhook` and refuses a plain-http store.
- **Pretty permalinks** — any structure other than "Plain" under **Settings → Permalinks**, so that webhook address exists.
- **A verified domain** — verify the store's domain in your TapTap dashboard. TapTap only sends customers back to a verified domain; until then they stay on TapTap's confirmation page after paying, and the order is completed by the webhook alone.

To disconnect, revoke the store under **Integrations** in your TapTap dashboard. That revokes its API key and webhook subscription.


## Versioning

Plugin releases are independent of the [TapTap API](https://github.com/TapTap-Pay/api) cadence. The plugin pins a known-good `taptap-pay/sdk` version in its `composer.lock`, bumped explicitly by the maintainer when the SDK ships a feature the plugin needs.

## Development

```bash
composer install
composer run lint     # php -l across src + includes
composer run test     # PHPUnit (when tests exist)
```

For testing against a local WooCommerce install, symlink this directory into `wp-content/plugins/taptap-pay`.

## License

[GPL-2.0-or-later](LICENSE).
