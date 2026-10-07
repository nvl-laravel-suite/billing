# NVL Billing — API and usage

## Quickstart

```sh
composer require nvl/billing:^5.0
php artisan nvl:install billing --dry-run
php artisan nvl:install billing
```

Required NVL dependencies: `nvl/core` (`^5.0`), `nvl/tenancy` (`^5.0`). Bind BillingManagementAccess, configure the plan catalog and gateway, and enable Billing explicitly. Use a persisted active TenantId and an authorized actor.
Review the published common config, select one migration owner, and run schema preflight before existing-table upgrades. The installer does not enable features or run migrations. Follow the detailed installation and capability sections below before invoking a storage/provider operation.

Inject `Nvl\Billing\Contracts\BillingPortalContract` in a host service. After supplying the trusted inputs described above, the first public call is:

```php
use Nvl\Billing\Contracts\BillingPortalContract;

/** @var BillingPortalContract $capability */
$result = $capability->url($tenant, $actor, $returnUrl);
```

Use the [event catalog](docs/events.md) and [Testing your app](#testing-your-app) below. The suite [getting-started guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/getting-started.md) provides a complete Comments host fixture; package archives retain their own local references.


[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

For support, [open an issue](https://github.com/nvl-laravel-suite/billing/issues). Report vulnerabilities through [private reporting](https://github.com/nvl-laravel-suite/billing/security/advisories/new). See [Contributing](CONTRIBUTING.md) and [Upgrading](UPGRADING.md).

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/billing:^5.0` |
| Module identifier | `nvl/billing` |
| PHP namespace | `Nvl\Billing` |
| Service provider | `Nvl\Billing\Providers\BillingServiceProvider` |
| Configuration | `config/nvl-billing.php` |

## Purpose and boundaries

Billing owns one Stripe customer per tenant, subscription state, Checkout attempts, consumed trial markers, and a read-only feature snapshot. It uses Laravel Cashier for Stripe subscription synchronization. It does not bill individual users, define an application catalog, measure usage, authorize app features by itself, or provide a customer-facing billing UI. The host application owns pricing presentation, billing administration policy, and feature enforcement.

The package requires `nvl/core`, `nvl/tenancy`, and `laravel/cashier`. Billing data stays on the configured central connection. A tenant has one billing account, independent of the user's login model. The 3.x `nvl/laravel-suite` metapackage does not install Billing; add it only to applications that need subscriptions. Explicit Cashier model adoption changes process-global registration; coordinate it with any other Cashier integration in the host application.

Global Cashier adoption is opt-in: `nvl-billing.adoption.cashier_models` replaces the three Cashier models, while `adoption.cashier_routes` suppresses native Cashier routes. Both default to `false`. NVL webhook ingress is independently enabled with `nvl-billing.routes.webhook.enabled`; enabling Billing services alone preserves host Cashier registration. Doctor reports active adoption and its targets.

## Requirements and installation

Use PHP 8.4+, Laravel 12–13, Stripe, and an active Tenancy tenant directory. Install the published package from Packagist, then configure it in the host application:

```bash
composer require nvl/billing:^5.0
php artisan vendor:publish --tag=nvl-billing-translations
php artisan vendor:publish --tag=nvl-billing-config
php artisan vendor:publish --tag=nvl-billing-skills
php artisan vendor:publish --tag=nvl-billing-migrations
php artisan migrate
php artisan nvl:billing:doctor --strict
```

Set `STRIPE_KEY`, `STRIPE_SECRET`, and `STRIPE_WEBHOOK_SECRET` through the host application's Cashier configuration. Register the Stripe webhook endpoint at `POST /nvl/billing/stripe/webhook`, subscribe at least to `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `customer.updated`, and `customer.deleted`, and keep Stripe signature verification enabled. The route is registered only when `billing.enabled=true`. Test it with Stripe test mode before accepting real customers. Use HTTPS in production.

Billing migrations are opt-in. For automatic vendor loading, set `nvl-billing.migrations.enabled=true` and do not publish `nvl-billing-migrations`. For host-owned migrations, publish `nvl-billing-migrations`, leave `nvl-billing.migrations.enabled=false`, and maintain the copied migrations in the application. Never run both sources; publishing retimestamps migrations. Enable `billing.enabled=true` only after schema, Stripe credentials, webhook secret, and the host's management binding are ready.

## Configuration and Stripe catalog

Publish `nvl-billing-config` and set stable plan keys to Stripe Price IDs. Configure features and limits in application code; Stripe Product and Price objects determine charges, while this allowlist determines which prices can be selected and which local plan each synced Price grants.

```php
return [
    'enabled' => true,
    'connection' => null, // Defaults to nvl-tenancy.connection.
    'migrations' => ['enabled' => false],
    'subscription_type' => 'default',
    'prices' => [
        'pro' => ['monthly' => env('STRIPE_PRICE_PRO_MONTHLY')],
    ],
    'access' => [
        'free' => ['features' => [], 'limits' => ['projects' => 1]],
        'plans' => [
            'pro' => ['features' => ['advanced_reports'], 'limits' => ['projects' => 25]],
        ],
    ],
    'trial' => ['days' => 14, 'require_payment_method' => true],
];
```

Each Price ID must be unique. A single plan may have monthly and yearly prices. Do not use client-supplied Price IDs or accept a Stripe Price solely because a browser sent it. Price changes belong in a reviewed configuration release. Avoid deleting an old Price mapping while active subscribers still use it, or they will resolve to the free policy. The package supports one subscription item per tenant; metered, add-on, and multi-item subscriptions need an explicit extension before they can grant access.

The optional trial is offered once per billing account. `trial.days=0` disables it; a positive trial must be at least two days for Stripe Checkout. `require_payment_method=false` lets eligible customers start without a card and configures Stripe to cancel at trial end if no payment method is added. Trial consumption is recorded after Stripe confirms a trial and persists across cancellation. The host is responsible for any additional abuse controls across newly created tenants.

## Application integration

Bind `Nvl\Billing\Contracts\BillingManagementAccess` in the host container to authorize the authenticated actor for the selected `TenantId`. The default binding denies management. Resolve the tenant from trusted host routing or session context, never from an untrusted checkout body. Call `UpdateBillingContactAction::execute($tenantId, $actor, $name, $email)` to maintain the tenant's explicit billing contact without changing an Auth profile; an existing Stripe customer is updated before the local record. Call `StartCheckoutAction::execute($tenantId, $actor, $email, $plan, $interval, $successUrl, $cancelUrl)` after authorization; it returns a hosted Checkout URL. Call `BillingPortal::url($tenantId, $actor, $returnUrl)` for subscription changes, payment methods, and cancellation through Stripe's customer portal. Validate allowed return URLs in the host before passing them. Do not unlock paid features from the Checkout success redirect; wait for a signed webhook or reconciliation.

For host application services, inject the focused workflow contracts. `StartCheckoutContract::execute` returns `CheckoutSession`, `UpdateBillingContactContract::execute` returns a `BillingAccount` identity/result handle, `BillingAccessContract::forTenant` returns `BillingSnapshot`, and `BillingPortalContract::url` returns the hosted portal URL. Their arguments and results match the existing concrete APIs.

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Contracts\BillingAccessContract;
use Nvl\Billing\Contracts\BillingPortalContract;
use Nvl\Billing\Contracts\StartCheckoutContract;
use Nvl\Billing\Contracts\UpdateBillingContactContract;
use Nvl\Billing\ValueObjects\CheckoutSession;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

final readonly class TenantBilling
{
    public function __construct(
        private StartCheckoutContract $checkout,
        private UpdateBillingContactContract $contact,
        private BillingAccessContract $access,
        private BillingPortalContract $portal,
    ) {}

    public function start(
        TenantId $tenant,
        Authenticatable $actor,
        string $name,
        string $email,
        string $plan,
        string $interval,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSession {
        $this->contact->execute($tenant, $actor, $name, $email);

        return $this->checkout->execute($tenant, $actor, $email, $plan, $interval, $successUrl, $cancelUrl);
    }

    public function reportsEnabled(TenantId $tenant): bool
    {
        return $this->access->forTenant($tenant)->allows('advanced_reports');
    }

    public function portalUrl(TenantId $tenant, Authenticatable $actor, string $returnUrl): string
    {
        return $this->portal->url($tenant, $actor, $returnUrl);
    }
}
```

The provider installs each default with transient `bindIf`, preserving a host instance or closure registered before discovery. A later `$app->instance(Contract::class, $substitute)` reaches services resolved afterward; previously constructed services retain their injected dependency. Tests may substitute these interfaces with a Mockery mock or their own implementation and return the declared native value/model handle. The concrete Actions and services remain available with their existing constructors. Keep `BillingGateway`, `SubscriptionReader`, and `BillingManagementAccess` as the Stripe, reconciliation, and authorization extension seams; authorization still denies by default.

Read access through `BillingAccessContract::forTenant($tenantId)`:

```php
$snapshot = app(\Nvl\Billing\Contracts\BillingAccessContract::class)->forTenant($tenantId);

if ($snapshot->allows('advanced_reports')) {
    // Show the host-owned report.
}

$projectLimit = $snapshot->limit('projects');
```

The snapshot grants the configured plan during active and trialing states, or a canceled subscription until its recorded end. It falls back to the configured free policy for unknown Prices, unsupported item counts, incomplete, paused, past-due, unpaid, or expired subscriptions. The host still enforces quotas atomically where resources are created; a displayed limit is not a concurrency control. Zero is the default for an unspecified limit.

## Webhooks and reconciliation

The webhook verifies Stripe's signature, records processed event IDs, and synchronizes the Cashier models in a database transaction without calling Stripe. Retry delivery is idempotent. Do not expose the webhook behind session authentication or CSRF protection. The package's route is its only HTTP surface; billing administration remains host-owned.

Schedule `php artisan nvl:billing:reconcile` at an interval suitable for your application, or run `php artisan nvl:billing:reconcile --tenant=<uuid>` after investigating one account. The command pages through Stripe subscriptions and repairs local state, including missed webhooks. It makes live Stripe API requests and should run with normal job monitoring and rate control. Webhooks can arrive out of order, so reconciliation is the safety net. Use `php artisan nvl:billing:doctor --strict --format=json` for deployment readiness; it reports booleans without printing secrets.

## Development and verification

From a standalone public Billing checkout, install dependencies and run `composer quality`. In this source workbench, run the Billing package Pest suite, PHPStan, Pint, and package-family validation. Exercise Checkout, trial end, cancellation, failed payments, webhook retries, portal return, and reconciliation with Stripe test mode before production activation. Local unit tests use a fake billing gateway and do not prove a live Stripe account's product or portal configuration.

## Supported PHP usage

The source `@api` declarations identify supported workflows, extension contracts, and value types. Public members marked `@internal` and untagged implementation types remain package-owned. Concrete Actions retain their existing constructors, qualifiers, and `execute()` signatures.

A package model returned or accepted by a public workflow is an identity/result handle. Use its declared type and `getKey()`, `getKeyName()`, `getMorphClass()`, `getRouteKey()`, `getRouteKeyName()`, `is()`, `isNot()`, and `relationLoaded()`. Read only explicitly declared in-memory `@nvl-consumer-read` fields; ordinary model PHPDocs and fillable attributes do not grant consumer reads. Obtain display projections through public reads. Persistence, additional model queries, relation access/loading, and generic model serialization are outside this contract. Host-model queries remain available, while traversal or aggregates of package capability relations require the package public reader or authorized adapter.

## Testing your app

Install `Nvl\Billing\Testing\FakeBillingGateway::fake($container)` and `FakeSubscriptionReader::fake($container)` before constructing host services. They replace the existing BillingGateway and SubscriptionReader interfaces with fresh instances whose queues/history are independent. Existing conditional provider defaults preserve pre-discovery substitutes; late installation affects newly constructed services. These helpers require the prepared Core major 5 recorder, without a testing-framework runtime dependency.

```php
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Testing\FakeBillingGateway;
use Nvl\Billing\Testing\FakeSubscriptionReader;
use Nvl\Support\Testing\FakeCall;

$account = new BillingAccount;
$account->setRawAttributes(['id' => 'account-1', 'tenant_id' => 'tenant-1', 'stripe_id' => 'cus_test_123']);
$gateway = FakeBillingGateway::fake($this->app)
    ->willReturn('portal', 'https://billing.example/portal')
    ->willReturn('updateCustomer', null);
$reader = FakeSubscriptionReader::fake($this->app)->willReturn('forCustomer', []);

$url = $this->app->make(BillingGateway::class)->portal($account, 'https://app.example/billing');
$this->app->make(BillingGateway::class)->updateCustomer($account, 'Example customer', 'customer@example.com');
$subscriptions = $this->app->make(SubscriptionReader::class)->forCustomer('cus_test_123');

expect($url)->toBe('https://billing.example/portal')->and($subscriptions)->toBe([]);
$gateway->assertCalled('portal', static fn (FakeCall $call): bool => $call->arguments['account'] === $account);
$reader->assertCalled('forCustomer');
```

Script Checkout with the actual `Nvl\Billing\ValueObjects\CheckoutSession`; its arguments retain `price`, `trialDays`, expiry and **attemptId**. Portal returns the exact scripted string; updateCustomer consumes a void script without saving or changing the BillingAccount handle. Reader scripts use `list<array<string, mixed>>` and are returned without transport conversion or reconciliation. Scripts are FIFO per native method; Closure values remain inert, scripted exceptions are rethrown unchanged, and every attempt is recorded before success/type failure/throw/exhaustion. Wrong types raise TypeError; unsupported names and negative assertion counts raise FakeExpectationFailed; missing scripts raise UnscriptedFakeCall. Assertion predicates receive Core's immutable FakeCall with method and named arguments, and counts are exact, including zero.

Inject these gateway/reader seams for host-only tests, or substitute the focused workflow contracts to isolate the host from owning workflows. Construct in-memory model handles/DTOs for substitution tests; factories, when provided by the owning package, are schema fixtures. Prepare fixtures before SQL and Laravel HTTP/Stripe transport/storage/queue guards. Real Billing Actions still persist accounts and reservations with a fake gateway, so retain their authorization, webhook/reconciliation and Stripe test-mode lifecycle tests. Explicitly include `vendor/nvl/core/support/consumer-audit.neon` in host development PHPStan configuration; see [Core's configuration](https://github.com/nvl-laravel-suite/core#opt-in-phpstan-consumer-boundary).

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine the read-only checks from loaded NVL package providers. Errors fail the gate, and strict mode also fails warnings. This package's existing Doctor command remains available and uses the same package-owned inspection service.

## Next major: isolated schema identities

Use `nvl-billing.tables.<logical-key>` for every table and `nvl-billing.connection` for its database connection. Null connection inherits `nvl-core.connection`, then Laravel's default. Tables are resolved at runtime by the package table definition helper.

| Logical key | New default | Previous name |
| --- | --- | --- |
| `accounts` | `nvl_billing_accounts` | `nvl_billing_accounts` |
| `subscriptions` | `nvl_billing_subscriptions` | `nvl_billing_subscriptions` |
| `subscription_items` | `nvl_billing_subscription_items` | `nvl_billing_subscription_items` |
| `webhook_events` | `nvl_billing_webhook_events` | `nvl_billing_webhook_events` |

Migration filenames contain `nvl_billing_`. Existing installations must complete the upgrade in `UPGRADING.md` before running new migrations. A pending creator rejects an existing target before that owned migration runs; use `nvl:schema:preflight` for an explicit whole-batch check; legacy storage with old history needs an ownership decision.

## Canonical configuration ownership

Use `nvl-billing` settings in `config/nvl-billing.php` and canonical package environment names. Old generic roots are foreign unless an upgrading NVL host explicitly selects them in Core's default-off compatibility. Canonical false/null/empty values win; no old roots are populated or written back. Keep logical package/resource IDs unchanged. Review [Core's rename inventory and cache/worker cutover](https://github.com/nvl-laravel-suite/core/blob/main/UPGRADING.md#major-5-canonical-configuration-and-environment).

## Testing your app

Inject the supported contract rather than constructing its concrete Action or querying package tables. Replace `Nvl\Billing\Contracts\BillingPortalContract` in Laravel's native container for a host-workflow test:

```php
use Nvl\Billing\Contracts\BillingPortalContract;

$double = Mockery::mock(BillingPortalContract::class);
$this->app->instance(BillingPortalContract::class, $double);
// Configure the exact url arguments and documented return value for your host case.
```

The package's conditional native binding preserves host substitutions. Production uses the real contract; test doubles do not prove its storage/authorization behavior.

A detached fixture for a returned identity/data handle is:

```php
use Nvl\Billing\Models\BillingAccount;
$fixture = BillingAccount::factory()->withoutParents()->make();
```

Ordinary `make()` may persist declared package parents. `withoutParents()->make()` disables parent expansion/admission for detached fixtures; use explicit persisted parents/owners and matching effective connections for a real `create()`. Factories do not authorize workflows, call Stripe, create backing Media objects or publish Template artifacts. Enabled tenancy requires explicit admitted persisted tenants/parents. Your host test installation supplies Faker; no test runner is a runtime package dependency.

Use `Nvl\Billing\Testing\FakeBillingGateway::fake($this->app)` and `FakeSubscriptionReader::fake($this->app)` for explicit FIFO provider responses; these do not synchronize subscriptions or simulate remote callbacks.

Use Laravel `Event::fake()`, `Queue::fake()`, `Mail::fake()` or `Storage::fake()` only for the effects the host test intends to isolate. Use real commits/listeners for timing proof. Add the optional Core consumer boundary rules to host PHPStan:

```neon
includes:
    - vendor/nvl/core/support/consumer-audit.neon
parameters:
    nvlConsumer:
        testPaths: [tests]
        tableNames: []
        exceptions: []
```

Rules read installed public metadata without suite boot. They flag internal symbols, package model queries/writes, capability relations and owned tables; they cannot prove dynamic code or runtime authorization. Exact exceptions require `file`, `identifier`, `symbol`, and a documented `reason`. New C3/C4/E tests, archives and guide execution remain pending until the integration phase records results.

### Shipped factory states

These runtime builders keep Laravel's native Factory API. The listed methods name explicit supported parent/owner/lifecycle states; follow each factory's native admission requirements. Detached examples above do not assert persistence validity.

| Factory | Explicit states |
| --- | --- |
| [`BillingAccountFactory`](database/factories/BillingAccountFactory.php) | `forTenant(Tenant $tenant)` |
| [`BillingSubscriptionFactory`](database/factories/BillingSubscriptionFactory.php) | `forAccount(BillingAccount $parent)` |
| [`BillingSubscriptionItemFactory`](database/factories/BillingSubscriptionItemFactory.php) | `forSubscription(BillingSubscription $parent)` |

## Error codes and events

All recognized package failures implement `Nvl\Support\Contracts\PackageException`; only `RespondableException` opts into safe response metadata. Keep native PHP programmer errors and Laravel/SDK exceptions distinct. The optional `PackageExceptionRenderer` is registered by the host in `withExceptions`; it leaves unrelated, marker-only and non-JSON handling to the host. Its JSON envelope is `{message:string, code:string, context:object}`. Request locale is host-owned; diagnostics/previous exceptions are not public copy. Event schemas and source connections are documented in [events](docs/events.md).

The table lists enum discriminators, including any successful codes retained for compatibility. A code is not itself an HTTP status; the throwing exception's `suggestedStatus()` is authoritative, especially legacy/custom constructors. Empty context renders as `{}`; only documented JSON-safe context is presented.

| Code | Suggested status | Public context | Translation key |
| --- | --- | --- | --- |
| `binding_required` | 500 | {} | `nvl-billing::responsecode.binding_required` |
| `operation_failed` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-billing::responsecode.operation_failed` |
| `feature_disabled` | 404 | Declared safe scalar/array map; otherwise `{}` | `nvl-billing::responsecode.feature_disabled` |
| `tenant_inactive` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-billing::responsecode.tenant_inactive` |
| `checkout_conflict` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-billing::responsecode.checkout_conflict` |
| `subscription_conflict` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-billing::responsecode.subscription_conflict` |
| `provider_identity_mismatch` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-billing::responsecode.provider_identity_mismatch` |
| `provider_payload_invalid` | 422 | Declared safe scalar/array map; otherwise `{}` | `nvl-billing::responsecode.provider_payload_invalid` |
| `operation_conflict` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-billing::responsecode.operation_conflict` |
| `payment_state_invalid` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-billing::responsecode.payment_state_invalid` |
| `refund_balance_exceeded` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-billing::responsecode.refund_balance_exceeded` |
| `reconciliation_required` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-billing::responsecode.reconciliation_required` |
| `invalid_configuration` | 500 | {} | `nvl-billing::responsecode.invalid_configuration` |
| `storage_unavailable` | 500 | Declared safe scalar/array map; otherwise `{}` | `nvl-billing::responsecode.storage_unavailable` |



## Required bindings

The shipped placeholders fail closed with Core `binding_required`/500 before capability work. These are configuration failures; a configured adapter must preserve native authorization/not-found failures for actual user denial. Register your implementations in the host AppServiceProvider::register(), using these exact contracts. The `App` classes below are host adapters you implement, not package-provided defaults.

```php
use Nvl\Billing\Contracts\BillingManagementAccess;
use App\Billing\HostBillingAccess;

public function register(): void
{
    $this->app->bind(BillingManagementAccess::class, HostBillingAccess::class);
}
```

| Host adapter contract | Required native signature |
| --- | --- |
| `BillingManagementAccess` | `assertCanManage(Authenticatable $actor, TenantId $tenant): void` |

`Authenticatable` is Laravel’s contract and `Model` is Eloquent’s base. Use trusted persisted host identity; never return an arbitrary request-provided principal or infer ownership from a matching amount. DTOs/enums come from this package; `TenantId` comes from neutral Core Tenancy.

The actor’s authority is specific to the supplied active tenant. The binding is required when `nvl-billing.enabled=true`; configuring a Stripe gateway does not supply management authorization.

Run `php artisan nvl:doctor --strict --format=json` after selecting the capability. RequiredBindings metadata inspection never executes your adapter factory or proves that a configured adapter authorizes correctly; retain host adapter integration tests.

## License

MIT. See [LICENSE](LICENSE) and [SECURITY.md](SECURITY.md).
