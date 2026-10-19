<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Billing\Actions\StartCheckoutAction;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingWebhookEvent;
use Nvl\Billing\Testing\FakeBillingGateway;
use Nvl\Billing\Tests\LegacyOwnerScopeTestCase;
use Nvl\Billing\ValueObjects\CheckoutSession;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

uses(LegacyOwnerScopeTestCase::class);

it('reuses the exact pending legacy provider attempt and customer after native activation', function (): void {
    $gateway = FakeBillingGateway::fake(app())->willReturn('checkout', new CheckoutSession('cs_recovered', 'https://example.test/recovered', CarbonImmutable::now()->addHour()));
    app()->bind(BillingManagementAccess::class, fn () => new class implements BillingManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, OwnerReference $owner): void {}
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () use ($gateway): void {
        $owner = app(OwnershipContext::class)->requireOwner();
        foreach ([1, 2] as $retry) {
            expect(app(StartCheckoutAction::class)->execute($owner, Actor::query()->findOrFail(1), 'legacy@example.test', 'pro', 'monthly', 'https://example.test/success', 'https://example.test/cancel')->id)->toBe('cs_recovered');
        }
        $gateway->assertCalled('checkout', times: 1);
        $calls = $gateway->calls('checkout');
        expect($calls[0]->arguments['attemptId'])->toBe(LegacyOwnerScopeTestCase::Attempt)
            ->and(BillingAccount::query()->sole()->stripe_id)->toBe('cus_cutover')
            ->and(BillingAccount::query()->sole()->id)->toBe(LegacyOwnerScopeTestCase::Account)
            ->and(BillingAccount::query()->sole()->getAttribute('tenant_id'))->toBe(LegacyOwnerScopeTestCase::Tenant);
    });
});

it('preserves processed legacy receipt deduplication across cutover without exposing its global partition', function (): void {
    config(['nvl-ownership.system_operations' => ['billing.provider-callback']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void {}
    });
    $body = json_encode(['id' => 'evt_cutover', 'type' => 'customer.updated', 'data' => ['object' => ['id' => 'cus_cutover', 'email' => 'changed@example.test']]], JSON_THROW_ON_ERROR);
    $stamp = time();
    $signature = hash_hmac('sha256', $stamp.'.'.$body, 'whsec_billing_test');
    $this->call('POST', '/nvl/billing/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_Stripe_Signature' => 't='.$stamp.',v1='.$signature], $body)->assertSuccessful();
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function (): void {
        expect(BillingAccount::query()->sole()->email)->toBe('legacy@example.test')
            ->and(BillingWebhookEvent::query()->count())->toBe(0);
    });
    expect(DB::table(BillingTables::get(BillingTables::WebhookEvents))->count())->toBe(1);
});
