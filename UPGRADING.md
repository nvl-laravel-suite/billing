# Upgrading NVL Billing

## Consumer contracts, committed events and runtime policy (5.x)

Prefer focused public interfaces in constructor injection; native implementations remain container defaults and host prebindings win. Returned models are documented identity/data handles: use package contracts for reads/writes and capability-specific batch readers instead of direct package queries. Enable the shipped Core PHPStan include in your host; do not invoke the suite workbench static audit command in a consumer.

Events now carry immutable schemaVersion=1 and scalar/DTO snapshots. Replace model-bearing event fields with the IDs listed in [events](docs/events.md); load only through an authorized public reader when needed. Only six declared legacy `*Event` names are retained as PHP aliases for major 5, removal no earlier than major 6. Migrate exact imports/listeners/fakes to canonical names, replace suffix wildcard patterns explicitly, drain old queued payloads, rebuild event caches and restart workers. Framework Verified/PasswordReset remain native classes. Source-connection callbacks are process-local after-commit publication, not a durable outbox or exactly-once delivery.

Package failures have a marker and optional response metadata. Opt into Core's JSON renderer deliberately; preserve existing host handlers and request-locale selection. Missing required host adapters produce `binding_required`/500; genuine configured authorization denial retains native handling. See the README error table and required-bindings section where applicable.

Factories ship in runtime package mappings for host tests. Ordinary make may persist parents; withoutParents()->make creates detached fixtures. Supply persisted native owners/parents and active tenants explicitly, retain source revisions, and never treat a factory row as a real storage/provider/workflow effect. Core's optional installer publishes common config without enabling features; strict Doctor and explicit deployment cache/worker steps belong in the host release process. C3/C4/E executable acceptance is pending until recorded by integration.


## Major 5: runtime gateway and reader fakes

`Nvl\Billing\Testing\FakeBillingGateway` and `FakeSubscriptionReader` implement the existing extension contracts without changing production provider defaults or concrete workflow constructors. Install each with `fake($container)` before resolving host services. The Core major 5 recorder keeps FIFO scripts and immutable named records per instance; assertion predicates receive FakeCall records. Checkout retains its native attemptId, customer updates remain void, and the reader accepts the declared subscription list shape. Unsupported names, wrong types, negative counts and exhaustion fail clearly. These helpers need no schema/data migration and do not establish real account lifecycle, authorization or delivery behavior. See [Testing your app](README.md#testing-your-app).

This is a new optional package with no prior NVL Billing schema to migrate. Install it separately from `nvl/laravel-suite` and configure a central billing connection. Do not map an existing user-based Cashier customer table to Billing's tenant account model without a reviewed data migration.

Before enabling Billing in an existing application, decide whether vendor-loaded or host-owned migrations will run, create Stripe catalog entries and recurring Prices, publish the Price allowlist, bind `BillingManagementAccess`, configure Cashier credentials and a signed webhook, and run `nvl:billing:doctor --strict`. Test a complete subscription lifecycle in Stripe test mode. Cashier's model registration is application-wide; applications already using Cashier with another customer model need a deliberate migration or separate service boundary.

When changing Stripe Prices, keep historical IDs in the catalog while existing subscriptions reference them. When changing access policy, review existing tenants because effective features and limits are resolved from current configuration. Deploy price/configuration changes before offering new Checkout choices.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=billing --claim-legacy --migration-owner=vendor --dry-run --format=json
php artisan nvl:schema:upgrade --package=billing --claim-legacy --migration-owner=vendor --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Declare each published path and canonical identity explicitly in `nvl-core.migrations.published`; retimestamped history also needs an exact `legacy` mapping. Use `--migration-owner=vendor` after manually archiving declared copies outside loaded paths, or `--migration-owner=published` after manually replacing executable copies with current migration code and disabling vendor loading. The plan verifies ownership and preserves batches; checksums do not automatically claim files. Modified host copies remain host-owned. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, run `nvl:schema:preflight` with the same selected paths and connection, then migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.

## Tagged consumer PHP boundary

Use source `@api` workflows, extension contracts, and value types for application integration. Direct use of untagged implementations or `@internal` members is unsupported. This classification keeps existing concrete Action signatures and runtime behavior; it does not authorize package model persistence, ad hoc queries, relation traversal, or generic model serialization. Returned models are identity/result handles with only the explicitly declared in-memory read fields described in the README.

## Focused workflow injection

Host services can now replace constructor dependencies on `StartCheckoutAction`, `UpdateBillingContactAction`, `BillingAccess`, and `BillingPortal` with `StartCheckoutContract`, `UpdateBillingContactContract`, `BillingAccessContract`, and `BillingPortalContract` respectively. The interfaces preserve each existing public method's arguments, result, and documentation. No database migration or Cashier adoption change accompanies this addition; concrete construction remains supported.

Each new default is transient and registered with `bindIf`. Bind a host implementation, closure, or instance before provider discovery, or replace the interface instance before resolving a new host service. Existing constructed services keep their injected dependency. The gateway, subscription-reader, and management-authorization extension contracts retain their conditional defaults. Interface substitutes isolate host orchestration; they do not establish native authorization, storage, or Stripe lifecycle correctness.
