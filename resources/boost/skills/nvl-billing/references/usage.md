# Subscription billing usage

Use [installation](installation.md) for exact provider/publish/migration choices and [API](api.md) for full declarations. Services live in `Nvl\Billing\Services`; contracts in `Nvl\Billing\Contracts`. Billing owns subscriptions; the application owns prices offered to users, permissions, routes and presentation.

## Capability selection and host decisions

| Need | Native operation | Result / condition |
| --- | --- | --- |
| Begin subscription checkout | `BillingCheckout::start(owner, actor, billingEmail, plan, interval, successUrl, cancelUrl)` | `array{id:string,url:string,expiresAt:CarbonImmutable}` |
| Edit the contact | `BillingContacts::update(owner, actor, name, email)` | Refreshed `BillingAccount`; provider synchronization when customer exists |
| Read entitlements | `BillingAccess::forOwner(owner)` | `array{plan:?string,state:string,features:list<string>,limits:array<string,int>}` |
| Manage the provider account | `BillingPortal::url(owner, actor, returnUrl)` | Authorized provider URL |
| Receive Stripe delivery | `BillingWebhooks::process(payload, signature)` | Verified, deduplicated state synchronization |
| Repair drift | `BillingReconciler::reconcile(owner)` | Refresh stored customer/subscriptions/items |
| Diagnose configuration | `BillingDoctor::inspect()` / `adoptionChecks()` | Diagnostic arrays |

Bind `BillingManagementAccess::assertCanManage(Authenticatable $actor, OwnerReference $owner): void` to the host policy. Throw on denial. Do not authorize by possession of an owner reference, background identity, current request or an unreviewed provider customer ID. Eloquent actors are reloaded before policy; other identity providers own freshness.

Obtain `Nvl\Support\Ownership\ValueObjects\OwnerReference` from trusted host/native ownership resolution. It contains `type`, `storage`, `keyType`, `key`, `version`; a tenant scalar is not interchangeable. With Ownership enabled, register/adopt the billing graph and enter admitted owner scope. Scheduled reconciliation needs explicit owner worklists and system-operation admission.

## Configuration and input semantics

Choose storage and migration ownership first. Vendor migration discovery requires both `nvl-billing.enabled=true` and `nvl-billing.migrations.enabled=true` before running migrations. Host-published migrations can run while Billing remains disabled; keep vendor migration loading false for that choice. Begin provider-backed operations only after schema, host bindings and provider configuration are ready.

Configure Laravel Cashier/Stripe credentials and the Stripe webhook secret. Native plan prices are a map such as `prices => ['pro' => ['monthly' => 'price_...']]`: names are stable lowercase aliases, each variant maps to a unique Stripe Price ID. Entitlements live in `access.free` and `access.plans`; features are nonempty string lists and limits are integer maps. The host chooses plan aliases, prices, free access and trial policy.

`checkout.return_hosts` is an exact HTTPS-host allowlist used by checkout and portal. URLs must omit credentials and nonstandard ports. Resolve URLs in the host and pass them explicitly. Contact strings and plan selection are validated by services; there is no generic writable Billing DTO or mass-assignment array. `null` is meaningful in returned plan facts, not a wildcard for owner selection. No explicit caller revision is accepted by these public scalar operations; the native observed-state fence owns concurrency.

## Verified native checkout composition

Preconditions: an admitted `$owner` of type `OwnerReference`, authenticated `$actor`, host management binding, enabled Billing, `pro/monthly` price configured, and `app.example.com` allowlisted. Run this from host orchestration outside an ambient Billing transaction:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Services\BillingCheckout;
use Nvl\Billing\Services\BillingContacts;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

function beginProSubscription(OwnerReference $owner, Authenticatable $actor): array
{
    app(BillingContacts::class)->update($owner, $actor, 'Example Company', 'billing@example.com');

    return app(BillingCheckout::class)->start(
        $owner,
        $actor,
        'billing@example.com',
        'pro',
        'monthly',
        'https://app.example.com/billing/success',
        'https://app.example.com/billing/cancel',
    );
}
```

Return the approved `url` to the browser. Read final entitlements through `BillingAccess::forOwner($owner)` after provider synchronization. A success redirect alone must not enable paid application features.

The host webhook controller calls:

```php
use Nvl\Billing\Services\BillingWebhooks;

app(BillingWebhooks::class)->process(
    $request->getContent(),
    (string) $request->header('Stripe-Signature', ''),
);
```

The host supplies middleware, route names and exception-to-response mapping. Preserve original request bytes for signature verification.

## Transactions, retries and replay

Checkout refuses an existing transaction before account/attempt creation or remote work. Its reserved pending attempt and idempotency key survive uncertain remote responses. Retry the same owner/plan/interval and URLs; do not manufacture another attempt to hide uncertainty. A different pending price or existing subscription can reject a new checkout.

An existing Stripe customer's contact update refuses a surrounding source transaction before provider work. Local-only contact writes can roll back with the host transaction. Reconciliation and subscription webhook refresh fetch complete provider facts before locking/applying local state; they refuse ambient source transactions. Older distinct subscription events cannot restore an older plan because current provider listing is authoritative.

An already processed webhook receipt returns without another provider read. Provider failures or transactional apply failures leave the receipt unprocessed for retry. A concurrent change to observed account, subscription or item state raises `reconciliation_required`; re-read provider facts and retry. Deleted customers remain historical identities. Source-connection events follow its outer commit; local callbacks are not a durable provider delivery guarantee.

## Models, adapters and operations

Choose `models.account` / `models.subscription` before provider boot. They must extend `BillingAccount` / `BillingSubscription` and preserve native keys, casts, hooks, relationships, configured tables and actual source connection. Subscription items and webhook receipts remain fixed. Package-level storage configuration moves the full graph; model replacement does not adopt existing data. Cashier global model adoption and route suppression are deliberate host configuration choices.

`BillingGateway` handles checkout/portal/customer writes; `SubscriptionReader` supplies complete provider subscription facts. Preserve conditional host bindings. `FakeBillingGateway` isolates provider requests; tests still need actual service persistence and replay assertions. Billing itself requires Cashier and Stripe; it is separately installable rather than an optional runtime stub.

Operational commands are `nvl:billing:doctor --strict --format=json` and `nvl:billing:reconcile --owner=<complete-owner-reference-JSON>`. A host can schedule reconciliation using reviewed owner worklists. Domain, authorization and invalid-input failures belong to host exception mapping. Raw model serialization is a host decision; project only intended account/subscription facts.
