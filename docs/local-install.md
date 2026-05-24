# Local install — symlink dev workflow

Walks you from a fresh Local-by-Flywheel WordPress install to a working
TapTap Pay gateway at the block-based Checkout. Captured during the
2026-05-24 smoke pass; every step here actually ran. The hairy bits
(missing composer, missing `buf/validate` PHP class, invisible-at-Blocks
gateway) are called out so you know which are first-time-only and which
are known plugin/SDK gaps.

If you're a store operator installing from a release zip, follow the
top-level [README](../README.md) instead. This doc is for plugin
maintainers iterating against a local WP.

## Prerequisites

- **Local by Flywheel** running a WordPress site with WooCommerce active
  and at least one published product. Tested against PHP 8.2, WP 7.0,
  WooCommerce 10.7. The site path Local creates is
  `~/Local Sites/<site-name>/app/public/`.
- **The taptap monorepo cloned** at `~/Projects/taptap/` (the plugin
  lives at `~/Projects/taptap/woocommerce/`).
- **Composer.** If you don't have one installed system-wide, Local
  ships one bundled at
  `/opt/Local/resources/extraResources/bin/composer/composer.phar` —
  the snippets below use it directly to avoid pulling another binary
  onto your machine.

## 1. Install PHP dependencies

The plugin's `composer.json` requires `taptap-pay/sdk` (sourced via the
VCS `repositories` entry pointing at `sdk-php`) and
`yahnis-elsts/plugin-update-checker`. Without `vendor/` the entry file
deactivates with an admin notice.

Use Local's bundled PHP 8.2 so the platform constraints in `composer.json`
match what WP will actually run with. Local ships its own glibc-bundled
shared libs, hence the `LD_LIBRARY_PATH`:

```bash
PHP_BIN=/home/ivanv/.config/Local/lightning-services/php-8.2.29+0/bin/linux/bin/php
SHARED=/home/ivanv/.config/Local/lightning-services/php-8.2.29+0/bin/linux/shared-libs
COMPOSER=/opt/Local/resources/extraResources/bin/composer/composer.phar

cd ~/Projects/taptap/woocommerce
LD_LIBRARY_PATH="$SHARED" "$PHP_BIN" "$COMPOSER" install --no-dev --prefer-dist --no-interaction
```

You'll see a `sh: symbol lookup error: ... rl_trim_arg_from_keyseq`
message scroll past — that's the `LD_LIBRARY_PATH` leaking into the
`sh` that composer execs for `unzip`. Composer notices, falls back to
its built-in `ZipArchive` extractor, and the install completes. Harmless.

Verify:

```bash
ls vendor/taptap-pay/sdk/src   # SDK is present
ls vendor/yahnis-elsts/plugin-update-checker
test -f vendor/autoload.php && echo "autoload ok"
```

## 2. Symlink the plugin into wp-content/plugins

Don't copy — symlink, so every code change in the monorepo is live
without a re-deploy. Replace `woocommerce-blank` with whatever your
Local site is called:

```bash
ln -s ~/Projects/taptap/woocommerce \
      "$HOME/Local Sites/woocommerce-blank/app/public/wp-content/plugins/taptap-pay"
```

Symlink target dir name **must be `taptap-pay`** — it has to match the
WP plugin slug used by `plugin_basename(TAPTAP_PAY_PLUGIN_FILE)` for
the activation redirect and update checker to resolve correctly.

## 3. Activate the plugin

WP Admin → Plugins → **Activate** under "TapTap Pay for WooCommerce".

On a clean activation you should be auto-redirected to the gateway
settings page (`/wp-admin/admin.php?page=wc-settings&tab=checkout&section=taptap_pay`).
If you land on the plugins list instead, the activation redirect was
suppressed by a bulk-activate flag or AJAX context — just click
**Settings** in the plugin row.

## 4. Configure credentials

The settings form needs:

- **API key** — `sk_test_…` for sandbox or `sk_live_…` for live. Mint
  in your TapTap dashboard.
- **Target wallet ID** — UUID of the wallet that should receive payouts.
- **API base URL** (Advanced) — leave blank for production
  (`https://api.taptap.rs`). For local-stack testing, point at your
  local API (e.g. `http://localhost:7777`).
- **Hosted checkout base URL** — leave at the `https://pay.taptap.rs`
  default unless you're running a non-prod checkout host.

Tick **Enable TapTap Pay at checkout**, then **Save changes**.

The plugin will attempt to auto-provision a webhook subscription
against the configured API on save. If your API isn't reachable, you'll
see an admin notice — settings still save, you can retry from the same
form by hitting Save again once the API is up.

If you tick **Enable** without filling both credentials and save, the
plugin refuses: it unticks Enable, emits an admin error listing the
missing fields, and skips the webhook provision attempt. Fill them in
and save again.

## 5. Verify at the customer-facing Checkout

Add any product to the cart and visit `/checkout/`. You should see the
**Payment options** card list **TapTap Pay** with the brand mark and
the description from your settings.

If the block-based checkout shows "There are no payment methods
available" but Payments admin shows the gateway as Active, you're on
an older plugin build that predates the Blocks integration — pull
latest, check that
[includes/BlocksGateway.php](../includes/BlocksGateway.php),
[assets/js/blocks-payment-method.js](../assets/js/blocks-payment-method.js),
and the `woocommerce_blocks_payment_method_type_registration` hook in
[includes/Plugin.php](../includes/Plugin.php) all exist.

## 6. Debug log (optional but recommended on first install)

`WP_DEBUG_LOG` writes structured PHP errors to
`wp-content/debug.log` without leaking them into rendered pages.
Useful while iterating; turn it off before benchmarking or shipping.

```bash
WPCONFIG="$HOME/Local Sites/woocommerce-blank/app/public/wp-config.php"
cp "$WPCONFIG" "$WPCONFIG.bak.taptap"
sed -i "s/define( 'WP_DEBUG', false );/define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );/" "$WPCONFIG"

# Tail while testing:
tail -f "$HOME/Local Sites/woocommerce-blank/app/public/wp-content/debug.log"

# Restore when done:
mv "$WPCONFIG.bak.taptap" "$WPCONFIG"
```

## Troubleshooting

### `Class "GPBMetadata\Buf\Validate\Validate" not found` on save

You're on a `vendor/taptap-pay/sdk` build older than the release that
includes the `clean-sdk-php-gen.sh` post-processor. The SDK's
generated proto stubs reference `\GPBMetadata\Buf\Validate\Validate::initOnce()`
because the `.proto` source files import `buf/validate/validate.proto`,
but the SDK deliberately doesn't ship the compiled-to-PHP form (see
the header of `api/buf.gen.sdk.php.yaml` in the api repo for the
closed-enum reason). Newer SDK releases strip the call at gen time.

Pull a newer SDK (`composer update taptap-pay/sdk`). If you're stuck
on an older release pinned by your `composer.lock`, run the cleanup
script manually against your installed vendor:

```bash
~/Projects/taptap/api/scripts/clean-sdk-php-gen.sh \
    ~/Projects/taptap/woocommerce/vendor/taptap-pay/sdk/gen
```

The script is idempotent — google/protobuf tolerates the leftover
validate extension bytes inside each descriptor as opaque options, so
stripping the init call is the whole fix.

### Gateway is "Active" in admin but absent at /checkout/

You're on the block-based Checkout (default since WC 8.3) and missing
the Blocks integration. Pull latest — `includes/BlocksGateway.php` +
`assets/js/blocks-payment-method.js` + the
`woocommerce_blocks_payment_method_type_registration` hook in
`Plugin::boot()` are the trifecta required.

If you're on the legacy shortcode checkout (rare; you'd have manually
replaced the Checkout block with the `[woocommerce_checkout]`
shortcode) the gateway works via the existing
`woocommerce_payment_gateways` filter without the Blocks pieces.

### `vendor/autoload.php is missing` admin notice

You activated the plugin without running `composer install`. Either
run it in place (step 1) or install from a release zip, which bundles
`vendor/`.

### Webhook auto-provision fails on save

Settings still save, but payment events won't be delivered until the
API is reachable from the WP host. If you're pointing at a local API
that's down, bring it up and hit **Save** again — the provisioner is
idempotent.

## Resetting to a clean state

```bash
# Deactivate via WP Admin → Plugins → Deactivate, then:
rm "$HOME/Local Sites/woocommerce-blank/app/public/wp-content/plugins/taptap-pay"
# Settings + webhook subscription stay in place (intentional — see
# Plugin::on_deactivation comments). To also wipe them, Delete the
# plugin from WP Admin; uninstall.php cleans up.
```
