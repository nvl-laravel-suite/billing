# NVL Billing

Owner-scoped subscription billing for PHP 8.4 and Laravel 13. Cashier and Stripe remain required because Billing uses their subscription models and provider protocol.

Consumers own data objects, Actions, controllers, routes and response resources. Native services retain authorization, canonical owner admission, checkout idempotency, transactions, committed events, reconciliation and signed webhook replay handling.

## Services

- `BillingCheckout::start(OwnerReference $owner, Authenticatable $actor, string $billingEmail, string $plan, string $interval, string $successUrl, string $cancelUrl): array` returns `array{id:string,url:string,expiresAt:CarbonImmutable}`. Pending attempts and provider idempotency keys survive retries.
- `BillingContacts::update(OwnerReference $owner, Authenticatable $actor, string $name, string $email): BillingAccount` synchronizes the contact through the configured gateway.
- `BillingAccess::forOwner(OwnerReference $owner): array` returns `array{plan:?string,state:string,features:list<string>,limits:array<string,int>}` from synchronized subscription state.
- `BillingPortal::url(OwnerReference $owner, Authenticatable $actor, string $returnUrl): string` creates an authorized provider portal URL.
- `BillingWebhooks::process(string $payload, string $signature): void` verifies the original bytes with Stripe's signature verifier, resolves the stored customer owner, deduplicates receipts and applies events transactionally.
- `BillingReconciler::reconcile(OwnerReference $owner): void` retains explicit owner worklists and maintenance authorization.

Bind `BillingManagementAccess` to host policy before management operations. Native ownership requires registered resources, an admitted owner and the existing adoption protocol. The deprecated Tenancy bridge is removed. Historical provider identities and populated adoption facts remain intact.

Billing registers no HTTP routes. Consumer webhook endpoints pass raw request bytes and the Stripe signature header to `BillingWebhooks`; consumers map domain failures to HTTP responses. Consumer DTOs and resources project returned models and arrays.

Enable `nvl-billing.enabled`, configure prices and the Stripe webhook secret, and choose one migration owner. Cashier model adoption and suppression of Cashier's own routes remain explicit configuration choices. Never load both published and automatic package migrations.

`BillingGateway` and `SubscriptionReader` are provider adapter boundaries. `FakeBillingGateway` accepts native session arrays for consumer tests. Billing stays separately installed; applications that do not need subscriptions can omit it.

See [events](docs/events.md), [upgrading](UPGRADING.md), [security](SECURITY.md) and [contributing](CONTRIBUTING.md).
