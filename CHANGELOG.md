# Changelog

All notable changes to TapTap Pay for WooCommerce are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versions follow [SemVer](https://semver.org/) independently of the upstream
TapTap-Pay API release cadence.

## [Unreleased]

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
