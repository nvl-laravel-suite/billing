# NVL Billing — API and usage

[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

For support, [open an issue](https://github.com/nvl-laravel-suite/billing/issues). Report vulnerabilities through [private reporting](https://github.com/nvl-laravel-suite/billing/security/advisories/new). See [Contributing](CONTRIBUTING.md) and [Upgrading](UPGRADING.md).

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/billing:^2.0` |
| Module identifier | `nvl/billing` |
| PHP namespace | `Nvl\Billing` |
| Service provider | `Nvl\Billing\Providers\BillingServiceProvider` |
| Configuration | `config/billing.php` |

## Purpose and boundaries

Billing owns one Stripe customer per tenant, subscription state, Checkout attempts, consumed trial markers, and a read-only feature snapshot. It uses Laravel Cashier for Stripe subscription synchronization. It does not bill individual users, define an application catalog, measure usage, authorize app features by itself, or provide a customer-facing billing UI. The host application owns pricing presentation, billing administration policy, and feature enforcement.

The package requires `nvl/core`, `nvl/tenancy`, and `laravel/cashier`. Billing data stays on the configured central connection. A tenant has one billing account, independent of the user's login model. The 3.x `nvl/laravel-suite` metapackage does not install Billing; add it only to applications that need subscriptions. Billing uses Cashier's process-global customer and subscription model registration, so do not load another independent Cashier billing integration in the same Laravel application.

## Requirements and installation

Use PHP 8.4+, Laravel 13, Stripe, and an active Tenancy tenant directory. Install the published package from Packagist, then configure it in the host application:

```bash
composer require nvl/billing:^2.0
php artisan vendor:publish --tag=billing-config
php artisan vendor:publish --tag=billing-skills
php artisan vendor:publish --tag=billing-migrations
php artisan migrate
php artisan nvl:billing:doctor --strict
```

Set `STRIPE_KEY`, `STRIPE_SECRET`, and `STRIPE_WEBHOOK_SECRET` through the host application's Cashier configuration. Register the Stripe webhook endpoint at `POST /nvl/billing/stripe/webhook`, subscribe at least to `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `customer.updated`, and `customer.deleted`, and keep Stripe signature verification enabled. The route is registered only when `billing.enabled=true`. Test it with Stripe test mode before accepting real customers. Use HTTPS in production.

Billing migrations are opt-in. For automatic vendor loading, set `billing.migrations.enabled=true` and do not publish `billing-migrations`. For host-owned migrations, publish `billing-migrations`, leave `billing.migrations.enabled=false`, and maintain the copied migrations in the application. Never run both sources; publishing retimestamps migrations. Enable `billing.enabled=true` only after schema, Stripe credentials, webhook secret, and the host's management binding are ready.

## Configuration and Stripe catalog

Publish `billing-config` and set stable plan keys to Stripe Price IDs. Configure features and limits in application code; Stripe Product and Price objects determine charges, while this allowlist determines which prices can be selected and which local plan each synced Price grants.

```php
return [
    'enabled' => true,
    'connection' => null, // Defaults to tenancy.connection.
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

Read access through `BillingAccess::forTenant($tenantId)`:

```php
$snapshot = app(\Nvl\Billing\Services\BillingAccess::class)->forTenant($tenantId);

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

## License

MIT. See [LICENSE](LICENSE) and [SECURITY.md](SECURITY.md).
