<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Services\BillingAccess;
use Nvl\Billing\Services\BillingSubscriptionSyncer;
use Nvl\Billing\Tests\OwnerScopeTestCase;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\OwnershipContext;

uses(OwnerScopeTestCase::class);

it('revokes access on provider deletion while preserving the immutable historical customer identity', function (): void {
    config(['nvl-ownership.system_operations' => ['billing.provider-callback']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void {}
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function (): void {
        BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_deleted']);
        app(BillingSubscriptionSyncer::class)->sync(['id' => 'sub_deleted', 'customer' => 'cus_deleted', 'status' => 'active',
            'items' => ['data' => [['id' => 'si_deleted', 'price' => ['id' => 'price_pro', 'product' => 'prod_native'], 'quantity' => 1]]]]);
        expect(app(BillingAccess::class)->forOwner(app(OwnershipContext::class)->requireOwner())->allows('reports'))->toBeTrue();
    });
    $body = json_encode(['id' => 'evt_delete', 'type' => 'customer.deleted', 'data' => ['object' => ['id' => 'cus_deleted']]], JSON_THROW_ON_ERROR);
    $stamp = time();
    $signature = hash_hmac('sha256', $stamp.'.'.$body, 'whsec_billing_test');
    foreach ([1, 2] as $replay) {
        $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_Stripe_Signature' => 't='.$stamp.',v1='.$signature], $body)->assertSuccessful();
    }
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function (): void {
        expect(BillingAccount::query()->sole()->stripe_id)->toBe('cus_deleted')
            ->and(BillingAccount::query()->sole()->provider_deleted_at)->not->toBeNull()
            ->and(app(BillingAccess::class)->forOwner(app(OwnershipContext::class)->requireOwner())->allows('reports'))->toBeFalse();
    });
});
