# Changelog

All notable changes to TapTap Pay for WooCommerce are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [SemVer](https://semver.org/) independently of the upstream
TapTap-Pay API release cadence.

## [Unreleased]

### Changed
- Ported to the TapTap-Pay API's `taptap.<context>.programmatic.v1`
  contract and the matching PHP SDK. A store connected under the previous
  API has to be connected again.
- Connecting a store: the merchant approves it in the TapTap dashboard and
  the store exchanges a one-time install code for its API key and webhook
  signing secret, server to server. Credentials are no longer posted
  through the browser, and both connect endpoints now require a store
  manager.
- Checkout goes through a single-use payment link. The order is matched to
  its payment through that link.
- Webhooks: `TapTap-Signature` header, the event envelope, and the
  endpoint challenge a new subscription has to answer.
- Refunds name the payment directly.
- The outcome of connecting is shown on the settings page; it used to be
  lost in the redirect.

### Removed
- Webhook provisioning from the settings page and webhook removal on
  uninstall. TapTap creates the subscription with the connection, and the
  store's API key cannot manage webhooks.

## [0.1.0] — 2026-05-23

### Added
- Hosted-checkout flow via the programmatic Payments API and the
  TapTap-Pay PHP SDK.
- Auto-provisioned webhook subscription on settings save (Create + reuse +
  rotate-secret paths).
- HMAC-SHA256 V2 webhook signature verification with 5-minute replay
  window, constant-time comparison.
- Refund support, routed to the funding PayIn transaction.
- HPOS compatibility declaration.
- GitHub-releases auto-update via Plugin Update Checker v5.
