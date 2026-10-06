<?php

declare(strict_types=1);

namespace Nvl\Billing\Providers;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;
use Nvl\Billing\Actions\StartCheckoutAction;
use Nvl\Billing\Actions\UpdateBillingContactAction;
use Nvl\Billing\Catalog\PlanCatalog;
use Nvl\Billing\Console\Commands\BillingDoctorCommand;
use Nvl\Billing\Console\Commands\BillingReconcileCommand;
use Nvl\Billing\Contracts\BillingAccessContract;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Contracts\BillingPortalContract;
use Nvl\Billing\Contracts\StartCheckoutContract;
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Contracts\UpdateBillingContactContract;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;
use Nvl\Billing\Services\BillingAccess;
use Nvl\Billing\Services\BillingDoctor;
use Nvl\Billing\Services\BillingPortal;
use Nvl\Billing\Services\DenyBillingManagementAccess;
use Nvl\Billing\Services\StripeBillingGateway;
use Nvl\Billing\Services\StripeSubscriptionReader;
use Nvl\Support\Doctor\DoctorCheck;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;

/** Registers the isolated Cashier models and opt-in Billing resources. */
final class BillingServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    /** Register Billing's configuration and suppress Cashier's global routes. */
    public function register(): void
    {
        PackageDoctorContributor::register($this->app, 'nvl/billing', function (): array {
            if (config('nvl-billing.enabled') !== true) {
                return [...$this->app->make(BillingDoctor::class)->adoptionChecks(), new DoctorCheck('enabled', 'info', true, 'Billing is disabled; enable it explicitly before configuring its integrations.')];
            }

            $report = $this->app->make(BillingDoctor::class)->inspect();

            return [...$this->app->make(BillingDoctor::class)->adoptionChecks(), ...PackageDoctorContributor::booleanChecks($report['checks'], 'nvl:billing:doctor')];
        });

        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-billing.php', 'billing');
        if (config('nvl-billing.adoption.cashier_routes') === true) {
            Cashier::ignoreRoutes();
        }
        $this->app->singleton(PlanCatalog::class, static fn (): PlanCatalog => PlanCatalog::fromConfig(config('nvl-billing.prices', [])));
        $this->app->bindIf(BillingManagementAccess::class, DenyBillingManagementAccess::class);
        $this->app->bindIf(BillingGateway::class, StripeBillingGateway::class);
        $this->app->bindIf(SubscriptionReader::class, StripeSubscriptionReader::class);
        $this->app->bindIf(StartCheckoutContract::class, StartCheckoutAction::class);
        $this->app->bindIf(UpdateBillingContactContract::class, UpdateBillingContactAction::class);
        $this->app->bindIf(BillingAccessContract::class, BillingAccess::class);
        $this->app->bindIf(BillingPortalContract::class, BillingPortal::class);
    }

    /** Publish the configuration and load only explicitly enabled migrations. */
    public function boot(): void
    {
        $path = __DIR__.'/../../database/migrations/billing';
        $this->commands([BillingDoctorCommand::class, BillingReconcileCommand::class]);

        if (config('nvl-billing.enabled') === true) {
            if (config('nvl-tenancy.enabled') !== true) {
                throw new InvalidArgumentException('Tenancy must be enabled before Billing.');
            }

            if (! is_string(config('cashier.webhook.secret')) || config('cashier.webhook.secret') === '') {
                throw new InvalidArgumentException('A Stripe webhook signing secret is required when Billing is enabled.');
            }

            if (config('nvl-billing.adoption.cashier_models') === true) {
                Cashier::useCustomerModel(BillingAccount::class);
                Cashier::useSubscriptionModel(BillingSubscription::class);
                Cashier::useSubscriptionItemModel(BillingSubscriptionItem::class);
            }
            if (config('nvl-billing.routes.webhook.enabled') === true) {
                $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
            }
        }

        $this->publishes([
            __DIR__.'/../../config/nvl-billing.php' => config_path('nvl-billing.php'),
        ], 'billing-config');
        $this->publishes([
            __DIR__.'/../../resources/boost/skills/nvl-billing' => base_path('.agents/skills/nvl-billing'),
        ], 'billing-skills');
        $this->publishesMigrations([$path => database_path('migrations')], 'billing-migrations');

        if (config('nvl-billing.enabled') === true && config('nvl-billing.migrations.enabled') === true) {
            $this->loadMigrationsFrom($path);
        }
    }
}
