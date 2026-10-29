# NVL Billing

Owner-scoped subscription billing for PHP 8.4 and Laravel 13. Cashier and Stripe remain required because Billing uses their subscription models and provider protocol.

Consumers own data objects, Actions, controllers, routes and response resources. Native services retain authorization, canonical owner admission, checkout idempotency, transactions, committed events, reconciliation and signed webhook replay handling.

## Services

- `BillingCheckout::start(OwnerReference $owner, Authenticatable $actor, string $billingEmail, string $plan, string $interval, string $successUrl, string $cancelUrl): array` returns `array{id:string,url:string,expiresAt:CarbonImmutable}`. Pending attempts and provider idempotency keys survive retries.
- `BillingContacts::update(OwnerReference $owner, Authenticatable $actor, string $name, string $email): BillingAccount` synchronizes the contact through the configured gateway.
- `BillingAccess::forOwner(OwnerReference $owner): array` returns `array{plan:?string,state:string,features:list<string>,limits:array<string,int>}` from synchronized subscription state.
- `BillingPortal::url(OwnerReference $owner, Authenticatable $actor, string $returnUrl): string` creates an authorized provider portal URL.
- `BillingWebhooks::process(string $payload, string $signature): void` verifies the original bytes with Stripe's signature verifier, resolves the stored customer owner, deduplicates receipts and refreshes subscription notifications through `SubscriptionReader` before applying current state transactionally.
- `BillingReconciler::reconcile(OwnerReference $owner): void` retains explicit owner worklists and maintenance authorization.

Bind `BillingManagementAccess` to host policy before management operations. Native ownership requires registered resources, an admitted owner and the existing adoption protocol. The deprecated Tenancy bridge is removed. Historical provider identities and populated adoption facts remain intact.

Billing registers no HTTP routes. Consumer webhook endpoints pass raw request bytes and the Stripe signature header to `BillingWebhooks`; consumers map domain failures to HTTP responses. Consumer DTOs and resources project returned models and arrays.

Enable `nvl-billing.enabled`, configure prices and the Stripe webhook secret, and choose one migration owner. Cashier model adoption and suppression of Cashier's own routes remain explicit configuration choices. Never load both published and automatic package migrations.

`BillingGateway` and `SubscriptionReader` are provider adapter boundaries. `FakeBillingGateway` accepts native session arrays for consumer tests. Billing stays separately installed; applications that do not need subscriptions can omit it.

## Provider work and retry boundaries

Checkout must start outside an existing transaction on the actual Billing connection. It refuses before creating an account, reserving an attempt or calling the gateway, so a caller rollback cannot discard a provider idempotency key after its remote use. Contact updates for an existing Stripe customer also refuse an ambient transaction before the gateway or local mutation. Local-only contact creation and updates without a Stripe customer still participate in the caller's transaction.

Signed subscription notifications trigger a complete current-and-historical subscription read for the stored customer; their mutable status, prices and cancellation fields are not authoritative snapshots. The matching canonical subscription is applied, or an existing local subscription is canceled when absent from that complete listing. Older distinct events therefore cannot restore an older plan. Customer contact/deletion notifications retain their signed lifecycle handling.

An exact processed receipt returns without another provider read. Subscription refresh starts outside a Billing transaction, validates provider customer identities and then locks/rechecks the observed account, subscription and items. A concurrent local change refuses the apply with `reconciliation_required`; retry with fresh provider facts. Provider failures and transactional persistence failures leave the receipt unprocessed for retry. Manual reconciliation remains available through `BillingReconciler`.

Tests and custom integrations that previously treated signed subscription payloads as the complete provider source must now bind or fake `SubscriptionReader` explicitly. No schema or dependency change is required.

See [events](docs/events.md), [upgrading](UPGRADING.md), [security](SECURITY.md) and [contributing](CONTRIBUTING.md).

## Configured root models

Set `nvl-billing.models.account` and `nvl-billing.models.subscription` before Laravel boots to concrete subclasses of `BillingAccount` and `BillingSubscription`. Native services, factories, relations, restoration and Ownership declarations resolve the frozen configured classes. Optional Cashier model adoption uses those same classes; native Billing relations do not require changing global Cashier models. Subscription items and webhook receipts retain their package classes.

Extensions retain native UUID keys, required casts, query and persistence hooks, foreign keys, and the package's financial ownership strategy. Root and technical records must use the same actual package connection and their configured native tables; selecting a separate connection or table only on a subclass is rejected before effects. Configure package-level `connection` and `tables` options instead. Model replacement does not adopt existing storage or alter the native schema contract.

Shared [service contracts, model extensions and composition](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/consumer-api.md) apply alongside the package-specific rules above.
