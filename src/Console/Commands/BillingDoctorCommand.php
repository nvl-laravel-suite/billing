<?php

declare(strict_types=1);

namespace Nvl\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Cashier;
use Nvl\Billing\Catalog\PlanCatalog;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Models\BillingAccount;

/** Checks the optional Billing package's deployment readiness. */
final class BillingDoctorCommand extends Command
{
    protected $signature = 'nvl:billing:doctor {--strict : Fail when Billing is enabled but incomplete} {--format=text : Output format: text or json}';

    protected $description = 'Inspect Stripe billing configuration and schema readiness';

    /** Report bounded configuration and schema checks without exposing secrets. */
    public function handle(): int
    {
        $format = $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('Output format must be text or json.');

            return self::FAILURE;
        }

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
        foreach ([BillingTables::Accounts, BillingTables::Subscriptions, BillingTables::SubscriptionItems, BillingTables::WebhookEvents] as $table) {
            $checks[$table] = ! $enabled || Schema::connection($connection)->hasTable($table);
        }

        $healthy = ! $enabled || ! in_array(false, $checks, true);
        if ($format === 'json') {
            $this->line(json_encode(['healthy' => $healthy, 'checks' => $checks], JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $name => $passed) {
                $this->line(sprintf('[%s] %s', $passed ? 'PASS' : 'FAIL', $name));
            }
        }

        return $healthy || ! $this->option('strict') ? self::SUCCESS : self::FAILURE;
    }
}
