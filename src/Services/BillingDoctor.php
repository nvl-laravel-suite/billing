<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Cashier;
use Nvl\Billing\Catalog\PlanCatalog;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Models\BillingAccount;

/** Checks the optional Billing package's deployment readiness. */
final class BillingDoctor
{
    /**
     * Inspect package readiness without rendering a command or changing state.
     *
     * @return array{healthy: bool, checks: array<string, bool>}
     */
    public function inspect(): array
    {

        $enabled = config('billing.enabled') === true;
        $checks = [
            'billing_enabled' => $enabled,
            'tenancy_enabled' => config('tenancy.enabled') === true,
            'stripe_secret' => is_string(config('cashier.secret')) && config('cashier.secret') !== '',
            'webhook_secret' => is_string(config('cashier.webhook.secret')) && config('cashier.webhook.secret') !== '',
            'cashier_customer_model' => Cashier::$customerModel === BillingAccount::class,
        ];

        try {
            PlanCatalog::fromConfig(config('billing.prices', []));
            $checks['price_catalog'] = config('billing.prices') !== [];
        } catch (\InvalidArgumentException|\TypeError) {
            $checks['price_catalog'] = false;
        }

        $configuredConnection = config('billing.connection') ?? config('tenancy.connection');
        $connection = is_string($configuredConnection) ? $configuredConnection : null;
        foreach ([BillingTables::get(BillingTables::Accounts), BillingTables::get(BillingTables::Subscriptions), BillingTables::get(BillingTables::SubscriptionItems), BillingTables::get(BillingTables::WebhookEvents)] as $table) {
            $checks[$table] = ! $enabled || Schema::connection($connection)->hasTable($table);
        }

        $healthy = ! $enabled || ! in_array(false, $checks, true);

        return ['healthy' => $healthy, 'checks' => $checks];
    }
}
