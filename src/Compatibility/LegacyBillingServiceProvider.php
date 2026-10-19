<?php

declare(strict_types=1);

namespace Nvl\Billing\Compatibility;

use Illuminate\Support\ServiceProvider;
use Nvl\Billing\Compatibility\Actions\StartCheckoutAction;
use Nvl\Billing\Compatibility\Actions\UpdateBillingContactAction;
use Nvl\Billing\Compatibility\Console\Commands\BillingReconcileCommand;
use Nvl\Billing\Compatibility\Contracts\BillingAccessContract;
use Nvl\Billing\Compatibility\Contracts\BillingManagementAccess;
use Nvl\Billing\Compatibility\Contracts\BillingPortalContract;
use Nvl\Billing\Compatibility\Contracts\StartCheckoutContract;
use Nvl\Billing\Compatibility\Contracts\UpdateBillingContactContract;
use Nvl\Billing\Compatibility\Services\BillingAccess;
use Nvl\Billing\Compatibility\Services\BillingPortal;
use Nvl\Billing\Compatibility\Services\DenyBillingManagementAccess;
use Nvl\Billing\Providers\BillingServiceProvider;

/**
 * Registers the explicit pre-adoption tenancy API for upgrading consumers.
 *
 * @api
 */
final class LegacyBillingServiceProvider extends ServiceProvider
{
    /** Keep old contracts behind an explicit provider instead of changing native signatures. */
    public function register(): void
    {
        $this->app->make('config')->set('nvl-billing.compatibility.tenancy', true);
        $this->app->register(BillingServiceProvider::class);
        $this->app->bindIf(BillingManagementAccess::class, DenyBillingManagementAccess::class);
        $this->app->bindIf(StartCheckoutContract::class, StartCheckoutAction::class);
        $this->app->bindIf(UpdateBillingContactContract::class, UpdateBillingContactAction::class);
        $this->app->bindIf(BillingAccessContract::class, BillingAccess::class);
        $this->app->bindIf(BillingPortalContract::class, BillingPortal::class);
    }

    /** Retain the old command syntax only when this bridge is explicitly installed. */
    public function boot(): void
    {
        $this->commands([BillingReconcileCommand::class]);
    }
}
