---
name: nvl-billing
description: Implement or review tenant-owned Stripe subscription billing with nvl/billing, including Checkout, trials, webhooks, access policies, and reconciliation.
---

# NVL Billing

Use this skill when integrating or changing tenant subscription billing in an application consuming `nvl/billing`.

## Tenant and payment boundaries

- Use a trusted `TenantId` and bind `BillingManagementAccess` for every management action. The default rejects access. Keep customer and subscription IDs out of client-controlled tenant selection.
- Resolve plan and interval through the configured Price catalog. Never let a request choose an arbitrary Stripe Price ID.
- Inject `StartCheckoutContract` for hosted subscription Checkout and `BillingPortalContract` for self-service changes. Unlock features only from `BillingAccessContract` after signed webhook synchronization or reconciliation.
- Inject `UpdateBillingContactContract` for an explicit tenant billing name and email; changes to a user's Auth profile do not update the Stripe customer.
- Keep payment keys and webhook secrets in host environment configuration. Verify webhook signatures; preserve event deduplication and Stripe idempotency keys.

## Host composition and tests

- Receive the four interfaces from `Nvl\Billing\Contracts` through constructor injection. Preserve native methods: `StartCheckoutContract::execute(TenantId, Authenticatable, string, string, string, string, string): CheckoutSession`, `UpdateBillingContactContract::execute(TenantId, Authenticatable, string, string): BillingAccount`, `BillingAccessContract::forTenant(TenantId): BillingSnapshot`, and `BillingPortalContract::url(TenantId, Authenticatable, string): string`.
- The provider uses transient `bindIf` defaults. Host instances and closures bound before discovery remain installed; late interface replacement reaches newly constructed host services. Keep existing concrete constructors available and retain `BillingGateway`, `SubscriptionReader`, and `BillingManagementAccess` for their existing extension roles.
- Substitute interfaces in host orchestration tests and return actual `CheckoutSession`, `BillingSnapshot`, or native unsaved `BillingAccount` handles. Do not execute owning database/Stripe workflows to construct substitute results. Measure effects after fixtures and provider setup; host isolation is separate from native authorization and lifecycle tests.

## Runtime gateway and reader fakes

- Install Testing\FakeBillingGateway::fake($container) and Testing\FakeSubscriptionReader::fake($container) before resolving host services. They implement existing gateway/reader interfaces and require Core's major 5 runtime FakeCalls helpers; existing conditional provider behavior stays unchanged.
- Script native CheckoutSession/string/void/list<array<string, mixed>> results explicitly. Checkout records attemptId, price, trialDays and expiry; customer updates retain the account handle without saving it. No customer creation, remote reads, reconciliation or account persistence runs inside these fakes.
- Scripts/history are per-instance FIFO; Closure values remain inert. Predicates receive immutable Nvl\Support\Testing\FakeCall records with method/named arguments. Counts are exact/non-negative, including zero; unsupported names, wrong result types and script exhaustion fail after recording invocation attempts as appropriate.
- Guard Stripe's SDK transport and Laravel HTTP/SQL/storage/queue after fixture construction. Owning Billing Actions still persist state with a fake gateway; keep authorization, schema, webhook/reconciliation and Stripe test-mode lifecycle coverage. Model factories remain the separate owning persistence fixture capability.

## Trials and access

- Offer a trial once per billing account. Record consumption from confirmed Stripe trial fields; canceled or expired trials remain consumed.
- Keep old Price mappings while subscriptions still reference them. Unknown Prices and unsupported item counts fall back to free access.
- Enforce quotas atomically in the consuming feature. A billing snapshot is a policy input, not a resource counter.

## Deployment

- Select either vendor-loaded or published billing migrations, then enable Billing after configuring Tenancy, Cashier, and the management adapter.
- Schedule `nvl:billing:reconcile` to repair missed or reordered webhooks, and run `nvl:billing:doctor --strict` at deployment.
- Test a complete subscription lifecycle in Stripe test mode, including cardless trials, failed payments, cancellation, webhook retries, and portal return.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine checks from loaded NVL providers. Retain the package Doctor command for its detailed report; both paths reuse the package-owned inspection service.

### Brownfield storage identities

Resolve all package tables through the table helper and canonical `nvl-billing.tables.*`, connections through `nvl-billing.connection` with Core/Laravel inheritance. Defaults use `nvl_billing_*`; migration filenames include that package slug. Never silently adopt a matching table or generic migration filename. Run shared `nvl:doctor --strict --format=json` and the explicit `nvl:schema:upgrade --package=billing --claim-legacy --dry-run --format=json` before upgrading owned legacy storage. Validate the complete plan and choose one migration owner. Preserve host records, constraint names and stored morph values. Deprecated config inputs last one major; canonical options take precedence.

## Canonical configuration ownership

- Read/write `nvl-billing` configuration and publish only canonical `nvl-<package>-<resource>` tags. Keep logical package/tenant resource identifiers unchanged.
- Generic config roots and unprefixed package environment names are foreign by default. For an upgrading NVL host only, select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` explicitly; both default off. Canonical presence wins, including false/null/empty values. Legacy inputs are read without writing back and are removed in major 6.
- Use canonical `NVL_<PACKAGE>_*` variables only in config evaluation, then rebuild configuration caches and restart workers after cutover. Shared Laravel environment variables retain their names. Consult Core's versioned `support/resources/global-names.json` for all renames.
- Old global aliases and legacy route families require separate explicit `global_aliases`/`legacy_routes` package selections. Preserve collisions and use Doctor diagnostics; never grant generic permissions automatically or claim signed-link compatibility without the same authorization/signature checks.


## Consumer runtime and testing contracts

Start with the package README Quickstart and Testing your app sections. Use `nvl:install <package>` for loaded-package common config publication; it does not enable features, run schema or refresh caches. Preserve native host owner keys/morph maps and selected auth/tenancy defaults. Read full runtime defaults and publish advanced config only deliberately.

Inject the supported focused interfaces and preserve host bindings. Returned model handles do not permit package-table queries/writes outside documented capability/extension seams. Host tests may substitute contracts in Laravel's container, use shipped model factories (ordinary make may persist parents; withoutParents()->make is detached), and use Laravel effect fakes deliberately. Only Media/Stripe have dedicated provider/library fakes; do not invent a universal package fake. Settings InteractsWithSettings is definition-only. Host PHPStan may include vendor/nvl/core/support/consumer-audit.neon; no unpublished workbench command is a consumer requirement.

Read docs/events.md and the package README error table. Domain events use schemaVersion=1, model-free facts and actual source-connection commit callbacks; only six declared old Event suffix aliases remain for major 5. Migrate exact listeners/fakes and suffix wildcards, drain old queued payloads, rebuild event cache and restart workers. Delivery is not a durable outbox. The Core exception renderer is opt-in, JSON-only for respondable failures, with exactly message/code/context and host-selected locale. Do not expose diagnostics or reinterpret missing bindings as authorization denial.

Core package logging uses nvl/normal with CSV quiet by default, stable message keys and bounded context; incidents survive quiet. Do not mutate global logger context or log raw row/provider/content/credential payloads. Run only authorized project checks and report new acceptance as pending until actual output exists.
