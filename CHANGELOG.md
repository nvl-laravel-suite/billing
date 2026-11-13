# Changelog


All notable changes to `nvl/billing` are documented here.

## [Unreleased]

## [7.0.4] - 2026-11-13

### Fixed

- Wrap concurrent contact and customer unique-constraint races in PostgreSQL-safe savepoints and harden customer synchronization guards.

<!-- NVL GENERATED CHANGES START -->
### Changed

- harden package runtime safety, PostgreSQL portability and test performance
<!-- NVL GENERATED CHANGES END -->

## [7.0.3] - 2026-11-11

### Fixed

- Store Stripe subscription timestamps (`trial_ends_at`, `ends_at`) as the correct instant when `app.timezone` is not UTC; they were previously shifted by the UTC offset, ending access and trials early. Rows written before this fix are corrected by the next subscription sync or reconciliation.
- A late-retried `customer.updated` event no longer restores an older name or email: the account records the provider `created` time of the last applied customer event (new nullable `provider_customer_updated_at` column, migration `2026_11_11_090000_nvl_billing_order_customer_updates`) and ignores older events.
- Enabled Billing suppresses Cashier's own routes by default, so Stripe cannot be answered by Cashier's `/stripe/webhook` controller and default customer model. Set `nvl-billing.adoption.keep_cashier_routes` to keep them; Doctor then warns with `adoption.cashier_webhook_route`. `adoption.cashier_routes` keeps its meaning.
- Two near-simultaneous subscription events for the same subscription are both acknowledged: the later callback re-reads current provider facts after the first commits (at most three provider reads) instead of answering Stripe with `reconciliation_required`.
- The account lifecycle and Checkout identity migrations can be rolled back, so `migrate:rollback`, `migrate:refresh` and the `DatabaseMigrations` test trait no longer fail; production still refuses while the accounts table holds rows.

<!-- NVL GENERATED CHANGES START -->
### Changed

- correct order customer updates, routes and migration rollbacks
<!-- NVL GENERATED CHANGES END -->

## [7.0.2] - 2026-11-09

### Changed

- Refresh the inherited Ownership deletion API reference for ordinary models and native pivots.

<!-- NVL GENERATED CHANGES START -->
### Changed

- refresh inherited ownership deletion contracts
<!-- NVL GENERATED CHANGES END -->

## [7.0.1] - 2026-11-05

### Fixed

- Fetch every Stripe subscription-item page before webhook synchronization or reconciliation treats the provider snapshot as complete, preserving items omitted from an embedded first page.

<!-- NVL GENERATED CHANGES START -->
### Changed

- load complete Stripe subscription items
<!-- NVL GENERATED CHANGES END -->

## [7.0.0] - 2026-11-03

- Adopt the reviewed NVL 7 family for PHP 8.4+ and Laravel 13, with compatible required and optional NVL peers. Review the package upgrade guide before deployment.

### Fixed

- Hide Stripe customer and Checkout recovery/capability fields from default account array/JSON serialization while retaining native service access.
- Bind Checkout replay and recovery to the original request and gateway configuration using a forward, nullable request-hash migration; refuse unverified historical active attempts until expiry.
- Keep enabled Billing boot and Doctor available when the webhook signing secret is missing, while webhook processing continues to fail closed.
- Continue all-owner reconciliation after admitted operational failures, report safe JSON success/failure counts and return failure when any owner fails; retain fail-fast ownership and admission checks.
- Complete account lifecycle storage with nullable historical tenant identities and persisted provider-deletion facts, using the canonical Billing/Core connection.
- Report missing enforcing Ownership runtime, reviewed adoption and account lifecycle schema in Billing Doctor; document the enabled-service requirement.

## [6.0.1] - 2026-10-21

### Changed

- Use the shared Ownership resource backfill while preserving native record keys, reviewed provider mappings and activation verification.
- Require optional Ownership >=6.0.1 when installed so new shared backfill callers cannot resolve an older runtime.

## [6.0.0] - 2026-10-20

### Release fixes

- Publish the existing native ownership default in common configuration and translate the owner-unavailable response in English and Bulgarian.

### Changed

- Add native owner-oriented billing contracts and configured BillingAccount roots without requiring Tenancy. Preserve provider customer/subscription correlation, idempotency and management authorization; retain tenant entry points only as deprecated bridge APIs.
- Align required/development NVL peers on `^6.0`, optional peer conflicts on major 6, and `dev-main` on `6.x-dev`. Review [UPGRADING.md](UPGRADING.md) before rollout.

## [5.0.1] - 2026-10-08

### Changed

- Correct published-family verification and adoption guidance for local Dagger CI; runtime contracts are unchanged.


## [5.0.0] - 2026-10-08

### Added

- Add runtime FakeBillingGateway and FakeSubscriptionReader with native typed responses, instance-owned FIFO scripts/call records and explicit container installation, without Stripe reads or account writes.

- Focused `StartCheckoutContract`, `UpdateBillingContactContract`, `BillingAccessContract`, and `BillingPortalContract` interfaces for host injection and orchestration testing, with conditional transient defaults preserving concrete constructors and native workflow results.

### Changed

- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Adopt lockstep major 5 with required and development NVL peer floors of `^5.0`.
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
