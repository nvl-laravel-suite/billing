<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Providers\BillingServiceProvider;
use Nvl\Billing\Services\BillingDoctor;
use Nvl\Billing\Services\BillingWebhooks;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'database.default' => 'billing_migration',
        'database.connections.billing_migration' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
    ]);
    app()->register(SupportServiceProvider::class);
    app()->register(BillingServiceProvider::class);
});

afterEach(function (): void {
    DB::purge('billing_migration');
});

it('installs nullable legacy identity and provider lifecycle storage without ownership adoption', function (): void {
    foreach (glob(__DIR__.'/../../database/migrations/billing/*.php') ?: [] as $path) {
        (require $path)->up();
    }
    $table = BillingTables::get(BillingTables::Accounts);
    DB::table($table)->insert(['id' => 'aee95d15-a557-470f-9b84-66e7f581bede', 'email' => 'billing@example.test', 'provider_deleted_at' => now()]);

    expect(DB::table($table)->sole()->tenant_id)->toBeNull()
        ->and(DB::getSchemaBuilder()->hasColumn($table, 'provider_deleted_at'))->toBeTrue()
        ->and(DB::getSchemaBuilder()->hasColumn($table, 'owner_scope_key'))->toBeFalse();
});

it('reports a missing enabled ownership runtime through doctor', function (): void {
    config(['nvl-billing.enabled' => true, 'nvl-ownership.enabled' => false]);

    $report = app(BillingDoctor::class)->inspect();

    expect($report['healthy'])->toBeFalse()
        ->and($report['checks']['ownership_runtime'])->toBeFalse();
});

it('boots enabled billing without a signing secret so doctor can report the configuration failure', function (): void {
    config(['nvl-billing.enabled' => true, 'cashier.webhook.secret' => '']);

    (new BillingServiceProvider(app()))->boot();
    $report = app(BillingDoctor::class)->inspect();

    expect($report['healthy'])->toBeFalse()->and($report['checks']['webhook_secret'])->toBeFalse()
        ->and(fn () => app(BillingWebhooks::class)->process('{}', 'invalid'))->toThrow(InvalidArgumentException::class, 'signing secret');
});

it('adds checkout request identity without inventing historical inputs or changing retained attempts', function (): void {
    (require __DIR__.'/../../database/migrations/billing/2026_09_28_000001_nvl_billing_create_billing_tables.php')->up();
    $table = BillingTables::get(BillingTables::Accounts);
    $id = 'bb15f114-f4b7-40ab-ae34-fd5d6338a166';
    DB::table($table)->insert([
        'id' => $id, 'tenant_id' => '994b0b59-2d77-4c0d-86e5-a38601e4da8b', 'email' => 'legacy@example.test',
        'pending_checkout_attempt_id' => '8c3db655-433c-4dc5-a2b3-536b0b579820', 'pending_checkout_price' => 'price_legacy',
        'pending_checkout_expires_at' => now()->addHour(),
    ]);
    $original = (array) DB::table($table)->where('id', $id)->sole();
    $migration = require __DIR__.'/../../database/migrations/billing/2026_11_01_174311_nvl_billing_bind_checkout_request_identity.php';
    $migration->up();
    $migration->up();
    $retained = (array) DB::table($table)->where('id', $id)->sole();

    expect($retained['pending_checkout_request_hash'])->toBeNull();
    unset($retained['pending_checkout_request_hash']);
    expect($retained)->toBe($original);
});
