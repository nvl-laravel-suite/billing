# Changelog

## Unreleased — consumer runtime integration

- Added focused consumer contract/testing guidance and shipped-factory usage limits.
- Versioned committed event payloads and documented canonical aliases, source connections, failure metadata and optional safe rendering.
- Added explicit first-use/installer and deployment guidance; new acceptance checks remain pending.


All notable changes to `nvl/billing` are documented here.

## [Unreleased]

### Added

- Add runtime FakeBillingGateway and FakeSubscriptionReader with native typed responses, instance-owned FIFO scripts/call records and explicit container installation, without Stripe reads or account writes.

- Focused `StartCheckoutContract`, `UpdateBillingContactContract`, `BillingAccessContract`, and `BillingPortalContract` interfaces for host injection and orchestration testing, with conditional transient defaults preserving concrete constructors and native workflow results.

### Changed

- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Prepare lockstep major 5 with required and development NVL peer floors of `^5.0`. This candidate has not been tagged or published.
- Gate Cashier model replacement, route adoption and package webhook ingress independently.
- Remain an optional installation outside the 21-member suite metapackage.
- Review [UPGRADING.md](UPGRADING.md) before adopting the new names and infrastructure boundaries.

## [2.0.2] - 2026-09-28

### Fixed

- Exclude generated PHPStan caches from Composer archives.

## [2.0.1] - 2026-09-28

### Documentation

- Clarify that Billing is available on Packagist and describe the published installation sequence.

## [2.0.0] - 2026-09-28

### Added

- Optional tenant-owned Stripe billing with hosted Checkout and customer portal entry points.
- Host-authorized billing contact updates independent of Auth profiles.
- One-use trials, synchronized Cashier subscriptions, signed idempotent webhooks, reconciliation, and conservative access snapshots.
- Opt-in migrations, deployment diagnostics, and packaged agent guidance.
