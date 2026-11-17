<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Ownership\BillingResourceRegistrar;
use Nvl\Billing\Providers\BillingServiceProvider;
use Nvl\Billing\Services\BillingDoctor;
use Nvl\Billing\Services\BillingWebhooks;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
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

it('reports missing customer event ordering storage through doctor', function (): void {
    $paths = glob(__DIR__.'/../../database/migrations/billing/*.php') ?: [];
    $ordering = array_values(array_filter($paths, static fn (string $path): bool => str_contains($path, 'order_customer_updates')));
    foreach (array_diff($paths, $ordering) as $path) {
        (require $path)->up();
    }
    config(['nvl-billing.enabled' => true, 'nvl-ownership.enabled' => false]);

    expect(app(BillingDoctor::class)->inspect()['checks']['customer_event_order_schema'])->toBeFalse();

    (require $ordering[0])->up();

    expect(app(BillingDoctor::class)->inspect()['checks']['customer_event_order_schema'])->toBeTrue();
});

it('refuses signed provider callbacks without native ownership before any effect', function (bool $ownershipEnabled, bool $scoped): void {
    foreach (glob(__DIR__.'/../../database/migrations/billing/*.php') ?: [] as $path) {
        (require $path)->up();
    }
    config(['nvl-billing.enabled' => true, 'nvl-ownership.enabled' => $ownershipEnabled, 'nvl-billing.ownership.scoped' => $scoped, 'cashier.webhook.secret' => 'whsec_unscoped']);
    (new BillingResourceRegistrar)->register(app(OwnershipResourceRegistry::class), $ownershipEnabled && $scoped);
    $table = BillingTables::get(BillingTables::Accounts);
    DB::table($table)->insert(['id' => '3f3c0f5e-74b5-4c47-9d0c-0f1b0dd8a6e1', 'email' => 'before@example.test', 'name' => 'Before', 'stripe_id' => 'cus_unscoped']);
    $payload = json_encode(['id' => 'evt_unscoped', 'type' => 'customer.updated', 'created' => time(), 'data' => ['object' => ['id' => 'cus_unscoped', 'name' => 'After', 'email' => 'after@example.test']]], JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_unscoped');

    expect(fn () => app(BillingWebhooks::class)->process($payload, "t={$timestamp},v1={$signature}"))
        ->toThrow(OwnershipViolation::class, 'Billing provider callbacks require native ownership.');
    expect(DB::table($table)->sole()->email)->toBe('before@example.test')
        ->and(DB::table(BillingTables::get(BillingTables::WebhookEvents))->count())->toBe(0);
})->with([
    'ownership disabled' => [false, true],
    'billing scoping disabled' => [true, false],
]);
