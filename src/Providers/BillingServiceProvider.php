<?php

declare(strict_types=1);

namespace Nvl\Billing\Providers;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;
use Nvl\Billing\Catalog\PlanCatalog;
use Nvl\Billing\Console\Commands\BillingDoctorCommand;
use Nvl\Billing\Console\Commands\BillingReconcileCommand;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;
use Nvl\Billing\Services\DenyBillingManagementAccess;
use Nvl\Billing\Services\StripeBillingGateway;
use Nvl\Billing\Services\StripeSubscriptionReader;
use Nvl\Support\Traits\MergesPackageConfiguration;

/** Registers the isolated Cashier models and opt-in Billing resources. */
final class BillingServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;

    /** Register Billing's configuration and suppress Cashier's global routes. */
    public function register(): void
    {
        $this->mergePackageConfiguration(__DIR__.'/../../config/billing.php', 'billing');
        Cashier::ignoreRoutes();
        $this->app->singleton(PlanCatalog::class, static fn (): PlanCatalog => PlanCatalog::fromConfig(config('billing.prices', [])));
        $this->app->bindIf(BillingManagementAccess::class, DenyBillingManagementAccess::class);
        $this->app->bindIf(BillingGateway::class, StripeBillingGateway::class);
        $this->app->bindIf(SubscriptionReader::class, StripeSubscriptionReader::class);
    }

    /** Publish the configuration and load only explicitly enabled migrations. */
    public function boot(): void
    {
        $path = __DIR__.'/../../database/migrations/billing';
        $this->commands([BillingDoctorCommand::class, BillingReconcileCommand::class]);

        if (config('billing.enabled') === true) {
            if (config('tenancy.enabled') !== true) {
                throw new InvalidArgumentException('Tenancy must be enabled before Billing.');
            }

            if (! is_string(config('cashier.webhook.secret')) || config('cashier.webhook.secret') === '') {
                throw new InvalidArgumentException('A Stripe webhook signing secret is required when Billing is enabled.');
            }

            Cashier::useCustomerModel(BillingAccount::class);
            Cashier::useSubscriptionModel(BillingSubscription::class);
            Cashier::useSubscriptionItemModel(BillingSubscriptionItem::class);
            $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        }

        $this->publishes([
            __DIR__.'/../../config/billing.php' => config_path('billing.php'),
        ], 'billing-config');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills/nvl-billing' => base_path('.agents/skills/nvl-billing'),
        ], 'billing-skills');
        $this->publishesMigrations([$path => database_path('migrations')], 'billing-migrations');

        if (config('billing.enabled') === true && config('billing.migrations.enabled') === true) {
            $this->loadMigrationsFrom($path);
        }
    }
}
