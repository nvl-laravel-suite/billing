<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Cashier;
use Nvl\Billing\Catalog\PlanCatalog;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Support\Doctor\DoctorCheck;

/** Checks the optional Billing package's deployment readiness. */
final class BillingDoctor
{
    /** @return list<DoctorCheck> */
    public function adoptionChecks(): array
    {
        return [
            new DoctorCheck('adoption.cashier_models', 'info', true, config('nvl-billing.adoption.cashier_models') === true
                ? 'Explicit Cashier model adoption: '.implode(', ', [Cashier::$customerModel, Cashier::$subscriptionModel, Cashier::$subscriptionItemModel])
                : 'Cashier models remain host-owned.'),
            new DoctorCheck('adoption.cashier_routes', 'info', true, config('nvl-billing.adoption.cashier_routes') === true
                ? 'Explicit adoption suppresses native Cashier routes.' : 'Native Cashier routes remain host-owned.'),
            new DoctorCheck('routes.webhook', 'info', true, config('nvl-billing.routes.webhook.enabled') === true
                ? 'NVL Billing webhook ingress is enabled.' : 'NVL Billing webhook ingress is disabled.'),
        ];
    }

    /**
     * Inspect package readiness without rendering a command or changing state.
     *
     * @return array{healthy: bool, checks: array<string, bool>}
     */
    public function inspect(): array
    {

        $enabled = config('nvl-billing.enabled') === true;
        $checks = [
            'billing_enabled' => $enabled,
            'tenancy_enabled' => config('nvl-tenancy.enabled') === true,
            'stripe_secret' => is_string(config('cashier.secret')) && config('cashier.secret') !== '',
            'webhook_secret' => is_string(config('cashier.webhook.secret')) && config('cashier.webhook.secret') !== '',
            'cashier_customer_model' => config('nvl-billing.adoption.cashier_models') !== true || Cashier::$customerModel === BillingAccount::class,
        ];

        try {
            PlanCatalog::fromConfig(config('nvl-billing.prices', []));
            $checks['price_catalog'] = config('nvl-billing.prices') !== [];
        } catch (BillingException|\InvalidArgumentException|\TypeError) {
            $checks['price_catalog'] = false;
        }

        $configuredConnection = config('nvl-billing.connection') ?? config('nvl-tenancy.connection');
        $connection = is_string($configuredConnection) ? $configuredConnection : null;
        foreach ([BillingTables::get(BillingTables::Accounts), BillingTables::get(BillingTables::Subscriptions), BillingTables::get(BillingTables::SubscriptionItems), BillingTables::get(BillingTables::WebhookEvents)] as $table) {
            $checks[$table] = ! $enabled || Schema::connection($connection)->hasTable($table);
        }

        $healthy = ! $enabled || ! in_array(false, $checks, true);

        return ['healthy' => $healthy, 'checks' => $checks];
    }
}
