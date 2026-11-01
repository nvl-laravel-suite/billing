# NVL Billing

Owner-scoped subscription billing for PHP 8.4 and Laravel 13. Cashier and Stripe remain required because Billing uses their subscription models and provider protocol.

Consumers own data objects, Actions, controllers, routes and response resources. Native services retain authorization, canonical owner admission, checkout idempotency, transactions, committed events, reconciliation and signed webhook replay handling.

BillingAccount array/JSON serialization hides the Stripe customer identifier and pending Checkout URL, attempt identifier, session identifier and request hash by default. Native services retain direct attribute access. Project explicitly authorized response fields instead of exposing account models or making recovery metadata visible.

## Services

- `BillingCheckout::start(OwnerReference $owner, Authenticatable $actor, string $billingEmail, string $plan, string $interval, string $successUrl, string $cancelUrl): array` returns `array{id:string,url:string,expiresAt:CarbonImmutable}`. Pending attempts and provider idempotency keys survive retries.
- `BillingContacts::update(OwnerReference $owner, Authenticatable $actor, string $name, string $email): BillingAccount` synchronizes the contact through the configured gateway.
- `BillingAccess::forOwner(OwnerReference $owner): array` returns `array{plan:?string,state:string,features:list<string>,limits:array<string,int>}` from synchronized subscription state.
- `BillingPortal::url(OwnerReference $owner, Authenticatable $actor, string $returnUrl): string` creates an authorized provider portal URL.
- `BillingWebhooks::process(string $payload, string $signature): void` verifies the original bytes with Stripe's signature verifier, resolves the stored customer owner, deduplicates receipts and refreshes subscription notifications through `SubscriptionReader` before applying current state transactionally.
- `BillingReconciler::reconcile(OwnerReference $owner): void` retains explicit owner worklists and maintenance authorization.

`nvl:billing:reconcile` without `--owner` freshly admits each owner and continues after that owner's provider or operational failure. It emits JSON `succeeded`/`failed` counts and exits nonzero if any reconciliation failed; provider payloads and exception messages are omitted. Invalid owner identities, readiness and ownership/admission failures still stop the command. An explicit `--owner` retains direct failure propagation.

Bind `BillingManagementAccess` to host policy before management operations. Native services reload canonical Eloquent actors before consulting host permissions; non-Eloquent identity freshness remains the host provider’s responsibility. Inject `BillingAccess` and `BillingPortal` directly; their duplicate workflow interfaces are removed. Native ownership requires registered resources, an admitted owner and the existing adoption protocol. The deprecated Tenancy bridge is removed. Historical provider identities and populated adoption facts remain intact.

Billing registers no HTTP routes. Consumer webhook endpoints pass raw request bytes and the Stripe signature header to `BillingWebhooks`; consumers map domain failures to HTTP responses. Consumer DTOs and resources project returned models and arrays.

Enable `nvl-billing.enabled`, configure prices, `nvl-billing.checkout.return_hosts` and the Stripe webhook secret, and choose one migration owner. Cashier model adoption and suppression of Cashier's own routes remain explicit configuration choices. Never load both published and automatic package migrations.

Owner-facing Billing services require installed and enabled `nvl/ownership`, host admission policies and reviewed adoption of the Billing resource graph. Composer leaves Ownership optional so a disabled Billing installation can boot without the enforcing runtime; this does not grant an unscoped billing mode. Follow the [Ownership adoption guide](https://github.com/nvl-laravel-suite/ownership#adoption-and-migration-ownership) after installing the selected Billing and Ownership migrations. `nvl:billing:doctor --strict --format=json` reports `ownership_runtime` and `ownership_adoption` separately, including live account identity constraints.

The account lifecycle migration makes the historical `tenant_id` optional and adds `provider_deleted_at` independently from ownership adoption. Ownership columns and their enforcement still enter through reviewed adoption. This forward migration preserves native accounts and provider-deletion facts on rollback; retirement requires an explicit reviewed migration. All Billing migrations use `nvl-billing.connection` or the shared Core default, matching the models.

`BillingGateway` and `SubscriptionReader` are provider adapter boundaries. `FakeBillingGateway` accepts native session arrays for consumer tests. Billing stays separately installed; applications that do not need subscriptions can omit it.

## Provider work and retry boundaries

Checkout and Portal return URLs must use an exact allowlisted HTTPS host, with no credentials or nonstandard port. Access policies require a list of nonempty feature names and a map of integer limits.

Checkout must start outside an existing transaction on the actual Billing connection. It refuses before creating an account, reserving an attempt or calling the gateway, so a caller rollback cannot discard a provider idempotency key after its remote use. Contact updates for an existing Stripe customer also refuse an ambient transaction before the gateway or local mutation. Local-only contact creation and updates without a Stripe customer still participate in the caller's transaction.

Signed subscription notifications trigger a complete current-and-historical subscription read for the stored customer; their mutable status, prices and cancellation fields are not authoritative snapshots. The matching canonical subscription is applied, or an existing local subscription is canceled when absent from that complete listing. Older distinct events therefore cannot restore an older plan. Customer contact/deletion notifications retain their signed lifecycle handling.

An exact processed receipt returns without another provider read. Subscription refresh starts outside a Billing transaction, validates provider customer identities and then locks/rechecks the observed account, subscription and items. A concurrent local change refuses the apply with `reconciliation_required`; retry with fresh provider facts. Provider failures and transactional persistence failures leave the receipt unprocessed for retry. Manual reconciliation through `BillingReconciler` uses the same observation fence for the account, all subscriptions and their items, and starts outside an ambient Billing transaction. Concurrent changes require a fresh retry. Disabled Billing rejects both reconciliation and webhook processing. Deleted provider customers remain historical facts and are not refreshed. When a subscription has no explicit type metadata, synchronization uses the configured `subscription_type`.

Tests and custom integrations that previously treated signed subscription payloads as the complete provider source must now bind or fake `SubscriptionReader` explicitly. No schema or dependency change is required.

## Retained history and host policy

Historical account/subscription foreign keys may cascade account deletion into subscription/item history; adoption preserves existing native foreign keys. Billing exposes no account hard-delete workflow. Retain financial account roots, use the provider-deletion marker for provider lifecycle, and prohibit raw deletion in host integrations. Any financial-history retirement or foreign-key replacement requires a separately reviewed host retention migration and backup; installing this release does not rewrite historical constraints.

Webhook event identifiers are retained as deduplication facts, including accepted unhandled notifications. There is no automatic receipt expiry. Monitor storage growth and retain replay protection when designing host archival; deleting receipts makes those event identities eligible for processing again.

Entitlement states are deliberately conservative: active/trialing subscriptions and canceled subscriptions with a future term can grant the configured paid plan. Past-due, unpaid and paused subscriptions use the free policy; unresolved subscriptions block a second Checkout and must be managed through the portal. There is no configurable grace-state list. Hosts needing commercial grace must implement an explicit application entitlement policy without changing provider facts or bypassing Billing admission.

Event account identifiers and nullable historical tenant identifiers are not owner grants. Preserve the owner context or an application-owned account-to-owner mapping and re-authorize follow-up work; do not infer a native owner from nullable tenantId. Internal model registries and framework/container helpers follow the suite's application-scoped composition conventions; hosts customize documented contracts and configured models rather than replacing internal registry machinery.

Every distinct subscription notification performs a complete, paginated current provider listing before applying state. This prevents stale signed snapshots from restoring old plans, at the cost of provider latency and rate-limit exposure. Budget webhook timeouts and provider retries accordingly; an already-processed receipt avoids the provider read. Strict Doctor checks enabled runtime, reviewed adoption, enforcing account identity constraints and lifecycle/request schema as well as tables and secrets.

See [events](docs/events.md), [upgrading](UPGRADING.md), [security](SECURITY.md) and [contributing](CONTRIBUTING.md).

## Configured root models

Set `nvl-billing.models.account` and `nvl-billing.models.subscription` before Laravel boots to concrete subclasses of `BillingAccount` and `BillingSubscription`. Native services, factories, relations, restoration and Ownership declarations resolve the frozen configured classes. Optional Cashier model adoption uses those same classes; native Billing relations do not require changing global Cashier models. Subscription items and webhook receipts retain their package classes.

Extensions retain native UUID keys, required casts, hidden recovery fields, query and persistence hooks, foreign keys, and the package's financial ownership strategy. Root and technical records must use the same actual package connection and their configured native tables; selecting a separate connection or table only on a subclass is rejected before effects. Configure package-level `connection` and `tables` options instead. Model replacement does not adopt existing storage or alter the native schema contract.

Shared [service contracts, model extensions and composition](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/consumer-api.md) apply alongside the package-specific rules above.
