# Upgrading NVL Billing

This is a new optional package with no prior NVL Billing schema to migrate. Install it separately from `nvl/laravel-suite` and configure a central billing connection. Do not map an existing user-based Cashier customer table to Billing's tenant account model without a reviewed data migration.

Before enabling Billing in an existing application, decide whether vendor-loaded or host-owned migrations will run, create Stripe catalog entries and recurring Prices, publish the Price allowlist, bind `BillingManagementAccess`, configure Cashier credentials and a signed webhook, and run `nvl:billing:doctor --strict`. Test a complete subscription lifecycle in Stripe test mode. Cashier's model registration is application-wide; applications already using Cashier with another customer model need a deliberate migration or separate service boundary.

When changing Stripe Prices, keep historical IDs in the catalog while existing subscriptions reference them. When changing access policy, review existing tenants because effective features and limits are resolved from current configuration. Deploy price/configuration changes before offering new Checkout choices.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=billing --claim-legacy --dry-run --format=json
php artisan nvl:schema:upgrade --package=billing --claim-legacy --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Unmodified published files, including changed timestamps, map by verified checksum to the exact vendor migration identity and current package migration implementation. Modified host copies remain host-owned. Disable vendor loading when retaining a published owner; duplicate ownership fails before migration. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.
