<?php

declare(strict_types=1);

namespace Nvl\Billing\Tests;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\Request;
use Nvl\Billing\Providers\BillingServiceProvider;
use Nvl\Billing\Services\BillingWebhooks;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Tests\OwnershipTestCase;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Stripe\Exception\SignatureVerificationException;

/** Activates native Billing roots without Tenancy, Auth or a synthetic owner. */
abstract class OwnerScopeTestCase extends OwnershipTestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), BillingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set([
            'nvl-billing.enabled' => true,
            'nvl-billing.checkout.return_hosts' => ['app.test', 'example.test'],
            'nvl-billing.ownership.scoped' => true,
            'nvl-billing.migrations.enabled' => false,
            'nvl-billing.adoption.cashier_models' => true,
            'nvl-billing.routes.webhook.enabled' => true,
            'cashier.webhook.secret' => 'whsec_billing_test',
            'cashier.secret' => 'sk_test_billing',
            'nvl-billing.prices' => ['pro' => ['monthly' => 'price_pro']],
            'nvl-billing.access.plans.pro' => ['features' => ['reports'], 'limits' => ['seats' => 10]],
        ]);
    }

    /** Consumer-owned webhook transport; domain verification remains in BillingWebhooks. */
    protected function defineRoutes($router): void
    {
        $router->post('/nvl/billing/stripe/webhook', function (Request $request) {
            try {
                app(BillingWebhooks::class)->process($request->getContent(), (string) $request->header('Stripe-Signature', ''));
            } catch (SignatureVerificationException) {
                abort(403);
            } catch (\InvalidArgumentException|\JsonException|\UnexpectedValueException) {
                abort(400);
            }

            return response()->noContent();
        })->name('nvl.billing.webhook');
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__.'/../database/migrations/billing/*.php') ?: [] as $path) {
            (require $path)->up();
        }
        (require __DIR__.'/../../ownership/database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
        $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
        {
            public function authorize(OwnershipOperation $operation): void {}
        });
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            private bool $enabled = true;

            public function activate(array $payload): void
            {
                $this->enabled = true;
            }

            public function deactivate(): void
            {
                $this->enabled = false;
            }

            public function active(): bool
            {
                return $this->enabled;
            }

            public function data(): array
            {
                return [];
            }
        });
        $coordinator = $this->app->make(OwnershipAdoptionCoordinator::class);
        $operation = new OwnershipOperation('billing-adopt', 'operator', '1');
        $plan = $coordinator->prepare(['billing'], $this->adoptionMappings(), $operation);
        while (! $coordinator->backfill($plan, 100, $operation)) {
        }
        $coordinator->activate($plan, $operation);
        $this->app->make(MaintenanceMode::class)->deactivate();
        $this->allowOwners();
    }

    /** Supply explicit reviewed pre-adoption records for nonempty cutover fixtures.
     * @return list<OwnershipMapping>
     */
    protected function adoptionMappings(): array
    {
        return [];
    }
}
