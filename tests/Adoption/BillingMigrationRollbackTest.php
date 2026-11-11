<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Providers\BillingServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

/** @return list<Migration> */
function billingRollbackMigrations(): array
{
    return array_map(
        static fn (string $path): Migration => require $path,
        glob(__DIR__.'/../../database/migrations/billing/*.php') ?: [],
    );
}

beforeEach(function (): void {
    app()->register(SupportServiceProvider::class);
    app()->register(BillingServiceProvider::class);
    foreach ([BillingTables::SubscriptionItems, BillingTables::Subscriptions, BillingTables::WebhookEvents, BillingTables::Accounts] as $table) {
        Schema::dropIfExists(BillingTables::get($table));
    }
    foreach (billingRollbackMigrations() as $migration) {
        $migration->up();
    }
});

afterEach(function (): void {
    foreach ([BillingTables::SubscriptionItems, BillingTables::Subscriptions, BillingTables::WebhookEvents, BillingTables::Accounts] as $table) {
        Schema::dropIfExists(BillingTables::get($table));
    }
});

it('rolls every Billing migration back and forward again outside production, with rows present', function (): void {
    $accounts = BillingTables::get(BillingTables::Accounts);
    DB::table($accounts)->insert(['id' => 'aee95d15-a557-470f-9b84-66e7f581bede', 'email' => 'billing@example.test']);
    $migrations = billingRollbackMigrations();

    foreach (array_reverse(array_slice($migrations, 1)) as $migration) {
        $migration->down();
    }

    expect(Schema::hasColumns($accounts, ['provider_deleted_at']))->toBeFalse()
        ->and(Schema::hasColumn($accounts, 'pending_checkout_request_hash'))->toBeFalse()
        ->and(Schema::hasColumn($accounts, 'provider_customer_updated_at'))->toBeFalse()
        ->and(DB::table($accounts)->count())->toBe(1);

    $migrations[0]->down();
    expect(Schema::hasTable($accounts))->toBeFalse();

    foreach ($migrations as $migration) {
        $migration->up();
    }
    expect(Schema::hasColumns($accounts, ['provider_deleted_at', 'pending_checkout_request_hash', 'provider_customer_updated_at']))->toBeTrue();
});

it('refuses to roll retained account storage back in production unless the accounts table is empty', function (string $file, string $column): void {
    $accounts = BillingTables::get(BillingTables::Accounts);
    $migration = require __DIR__.'/../../database/migrations/billing/'.$file;
    app()->detectEnvironment(static fn (): string => 'production');
    DB::table($accounts)->insert(['id' => 'aee95d15-a557-470f-9b84-66e7f581bede', 'email' => 'billing@example.test']);

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'reviewed retirement migration')
        ->and(Schema::hasColumn($accounts, $column))->toBeTrue();

    DB::table($accounts)->delete();
    $migration->down();

    expect(Schema::hasColumn($accounts, $column))->toBeFalse();
    $migration->down();
})->with([
    'account lifecycle' => ['2026_11_01_080624_nvl_billing_complete_account_lifecycle_storage.php', 'provider_deleted_at'],
    'checkout request identity' => ['2026_11_01_174311_nvl_billing_bind_checkout_request_identity.php', 'pending_checkout_request_hash'],
]);
