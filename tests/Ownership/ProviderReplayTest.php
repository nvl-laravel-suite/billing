<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Ownership\BillingIntegrityGuard;
use Nvl\Billing\Services\BillingAccess;
use Nvl\Billing\Tests\OwnerScopeTestCase;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnerScopeTestCase::class);

it('resolves signed provider events only through the stored customer owner and preserves replay identities', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), fn () => BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_native']));
    $body = json_encode(['id' => 'evt_native', 'type' => 'customer.subscription.created', 'data' => ['object' => [
        'id' => 'sub_native', 'customer' => 'cus_native', 'status' => 'active', 'metadata' => ['type' => 'default', 'owner_id' => 8],
        'items' => ['data' => [['id' => 'si_native', 'price' => ['id' => 'price_pro', 'product' => 'prod_native'], 'quantity' => 1]]],
    ]]], JSON_THROW_ON_ERROR);
    $stamp = time();
    $signature = hash_hmac('sha256', $stamp.'.'.$body, 'whsec_billing_test');
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_Stripe_Signature' => 't='.$stamp.',v1='.$signature];
    $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertForbidden();
    $this->withoutExceptionHandling();
    expect(fn () => $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], $headers, $body))->toThrow(OwnershipViolation::class);
    config(['nvl-ownership.system_operations' => ['billing.provider-callback']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void
        {
            if ($operation !== 'billing.provider-callback' || $owner->getKey() !== 7) {
                throw new OwnershipViolation;
            }
        }
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->findOrFail(1), function () use ($headers, $body): void {
        $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], $headers, $body)->assertSuccessful();
        expect(app(OwnershipContext::class)->requireOwner()->key)->toBe('8')->and(BillingSubscription::query()->count())->toBe(0);
    });
    $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], $headers, $body)->assertSuccessful();
    expect(DB::table(BillingTables::get(BillingTables::WebhookEvents))->count())->toBe(1);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function (): void {
        expect(app(BillingAccess::class)->forOwner(app(OwnershipContext::class)->requireOwner())->allows('reports'))->toBeTrue()
            ->and(BillingSubscription::query()->sole()->stripe_id)->toBe('sub_native');
        $account = BillingAccount::query()->sole();
        expect(fn () => DB::table($account->getTable())->where('id', $account->id)->update(['stripe_id' => 'cus_replacement']))->toThrow(QueryException::class);
    });
});

it('denies billing effects when customer correlation uniqueness is missing', function (): void {
    $model = new BillingAccount;
    $schema = $model->getConnection()->getSchemaBuilder();
    $name = null;
    foreach ($schema->getIndexes($model->getTable()) as $index) {
        if ($index['unique'] && $index['columns'] === ['stripe_id']) {
            $name = $index['name'];
        }
    }
    expect($name)->toBeString();
    $schema->table($model->getTable(), fn (Blueprint $table) => $table->dropUnique($name));
    expect(fn () => app(BillingIntegrityGuard::class)->assertReady())
        ->toThrow(OwnershipSchemaNotReady::class);
});
