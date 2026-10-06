<?php

declare(strict_types=1);

namespace Nvl\Billing\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Billing\Providers\BillingServiceProvider;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Orchestra\Testbench\TestCase;

/** Boots an enabled Billing package against an isolated central database. */
abstract class BillingTestCase extends TestCase
{
    use DatabaseMigrations;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            SupportServiceProvider::class,
            DataServiceProvider::class,
            TenancyServiceProvider::class,
            BillingServiceProvider::class,
        ];
    }

    /** Configure isolated billing and tenancy schema for feature tests. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nvl-tenancy.enabled' => true,
            'nvl-tenancy.migrations.enabled' => true,
            'nvl-billing.enabled' => true,
            'nvl-billing.adoption.cashier_models' => true,
            'nvl-billing.adoption.cashier_routes' => true,
            'nvl-billing.routes.webhook.enabled' => true,
            'nvl-billing.migrations.enabled' => true,
            'cashier.webhook.secret' => 'whsec_billing_test',
            'cashier.secret' => 'sk_test_billing',
        ]);
    }
}
