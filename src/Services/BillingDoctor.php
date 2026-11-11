<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Laravel\Cashier\Cashier;
use Nvl\Billing\Catalog\PlanCatalog;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Ownership\BillingIntegrityGuard;
use Nvl\Support\Doctor\DoctorCheck;
use Nvl\Support\Ownership\Exceptions\OwnershipException;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Checks the optional Billing package's deployment readiness.
 *
 * @internal
 */
final class BillingDoctor
{
    /** Inspect native readiness without admitting an owner or depending on the optional runtime. */
    public function __construct(private readonly OwnershipResourceRegistry $resources, private readonly BillingIntegrityGuard $integrity) {}

    /** @return list<DoctorCheck> */
    public function adoptionChecks(): array
    {
        return [
            new DoctorCheck('adoption.cashier_models', 'info', true, config('nvl-billing.adoption.cashier_models') === true
                ? 'Explicit Cashier model adoption: '.implode(', ', [Cashier::$customerModel, Cashier::$subscriptionModel, Cashier::$subscriptionItemModel])
                : 'Cashier models remain host-owned.'),
            new DoctorCheck('adoption.cashier_routes', 'info', true, Cashier::$registersRoutes
                ? 'Native Cashier routes remain host-owned.' : 'Billing suppresses native Cashier routes.'),
            $this->cashierWebhookRouteCheck(),
        ];
    }

    /** Warn when Stripe events could be answered by Cashier's controller instead of BillingWebhooks. */
    private function cashierWebhookRouteCheck(): DoctorCheck
    {
        $conflict = config('nvl-billing.enabled') === true && Route::has('cashier.webhook');

        return new DoctorCheck('adoption.cashier_webhook_route', 'warning', ! $conflict, $conflict
            ? 'Cashier\'s own webhook route is registered next to Billing: a Stripe endpoint pointed at it is handled by Cashier\'s controller and customer model, not BillingWebhooks. Remove nvl-billing.adoption.keep_cashier_routes or exclude that route, and point Stripe only at the application endpoint that calls BillingWebhooks::process().'
            : 'Cashier\'s own webhook route is not registered next to Billing.');
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
            'stripe_secret' => is_string(config('cashier.secret')) && config('cashier.secret') !== '',
            'webhook_secret' => is_string(config('cashier.webhook.secret')) && config('cashier.webhook.secret') !== '',
            'return_hosts' => is_array(config('nvl-billing.checkout.return_hosts')) && config('nvl-billing.checkout.return_hosts') !== [],
            'cashier_customer_model' => config('nvl-billing.adoption.cashier_models') !== true || Cashier::$customerModel === BillingModelRegistry::accountClass(),
            'ownership_runtime' => ! $enabled || (config('nvl-ownership.enabled') === true && $this->resources->get('billing.accounts')->ownership->strategy !== 'unscoped'),
        ];

        try {
            PlanCatalog::fromConfig(config('nvl-billing.prices', []));
            $checks['price_catalog'] = config('nvl-billing.prices') !== [];
        } catch (BillingException|\InvalidArgumentException|\TypeError) {
            $checks['price_catalog'] = false;
        }

        $configuredConnection = (BillingModelRegistry::newAccount())->getConnectionName();
        $connection = is_string($configuredConnection) ? $configuredConnection : null;
        foreach ([BillingTables::get(BillingTables::Accounts), BillingTables::get(BillingTables::Subscriptions), BillingTables::get(BillingTables::SubscriptionItems), BillingTables::get(BillingTables::WebhookEvents)] as $table) {
            $checks[$table] = ! $enabled || Schema::connection($connection)->hasTable($table);
        }
        $accounts = BillingTables::get(BillingTables::Accounts);
        $checks['account_lifecycle_schema'] = ! $enabled || ($checks[$accounts] && Schema::connection($connection)->hasColumn($accounts, 'provider_deleted_at'));
        $checks['checkout_request_schema'] = ! $enabled || ($checks[$accounts] && Schema::connection($connection)->hasColumn($accounts, 'pending_checkout_request_hash'));
        $checks['customer_event_order_schema'] = ! $enabled || ($checks[$accounts] && Schema::connection($connection)->hasColumn($accounts, 'provider_customer_updated_at'));
        $checks['ownership_adoption'] = ! $enabled;
        if ($enabled && $checks['ownership_runtime']) {
            try {
                $this->integrity->assertReady();
                $checks['ownership_adoption'] = true;
            } catch (OwnershipException|QueryException|JsonException) {
                $checks['ownership_adoption'] = false;
            }
        }

        $healthy = ! $enabled || ! in_array(false, $checks, true);

        return ['healthy' => $healthy, 'checks' => $checks];
    }
}
