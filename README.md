# TapTap Pay for WooCommerce

[![CI](https://github.com/TapTap-Pay/woocommerce/actions/workflows/ci.yml/badge.svg)](https://github.com/TapTap-Pay/woocommerce/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

Official WooCommerce payment gateway for [TapTap-Pay](https://usetaptap.com).

## What you get

- **Hosted checkout** — customers are redirected to TapTap's PCI-scoped checkout host, no card data touches your store.
- **Auto-provisioned webhook** — paste your API key, save, and the plugin reaches out to the TapTap API to create and verify a webhook subscription pointed at your store.
- **V2 HMAC-SHA256 signature verification** — every webhook delivery is verified against the per-subscription secret with a 5-minute replay window, in constant time.
- **Refunds** — refund from the standard WooCommerce order admin "Refund" button. Routes to the underlying TapTap PayIn transaction automatically.
- **Self-updating** — uses [Plugin Update Checker v5](https://github.com/YahnisElsts/plugin-update-checker) against [this repo's GitHub releases](https://github.com/TapTap-Pay/woocommerce/releases). WP polls, downloads, and installs new versions through the standard plugin-update UI.
- **HPOS-compatible** — works against both the legacy WP-post order store and WooCommerce's new high-performance order storage.

## Install

Grab the latest `taptap-pay-<version>.zip` from the [releases page](https://github.com/TapTap-Pay/woocommerce/releases) and upload it via **WP Admin → Plugins → Add New → Upload Plugin**.

After activation you'll be auto-redirected to the gateway settings. Paste:

- **API key** — minted in the [TapTap dashboard](https://app.usetaptap.com/settings/api-keys) (`sk_test_…` for sandbox, `sk_live_…` for live).
- **Wallet ID** — the UUID of the wallet that should receive payouts.

Hit **Save changes** — the plugin auto-creates the webhook subscription. Then tick **Enable TapTap Pay at checkout** and save again.

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
