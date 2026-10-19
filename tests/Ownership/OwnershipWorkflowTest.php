<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Actions\StartCheckoutAction;
use Nvl\Billing\Actions\UpdateBillingContactAction;
use Nvl\Billing\Compatibility\LegacyBillingServiceProvider;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;
use Nvl\Billing\Services\BillingAccess;
use Nvl\Billing\Testing\FakeBillingGateway;
use Nvl\Billing\Tests\OwnerScopeTestCase;
use Nvl\Billing\ValueObjects\CheckoutSession;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

uses(OwnerScopeTestCase::class);

beforeEach(function (): void {
    app()->instance(BillingGateway::class, (new FakeBillingGateway)->willReturn('checkout', new CheckoutSession('cs_native', 'https://example.test/checkout', CarbonImmutable::now()->addHour())));
    app()->bind(BillingManagementAccess::class, fn () => new class implements BillingManagementAccess
    {
        public function assertCanManage(Authenticatable $actor, OwnerReference $owner): void
        {
            if ($actor->getAuthIdentifier() !== 1 || ! in_array($owner->key, ['7', '8'], true)) {
                throw new OwnershipViolation;
            }
        }
    });
});

it('uses native owner references for checkout and access with no tenant lifecycle or synthetic IDs', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function (): void {
        $owner = app(OwnershipContext::class)->requireOwner();
        app(UpdateBillingContactAction::class)->execute($owner, Actor::query()->findOrFail(1), 'Billing', 'billing@example.test');
        $first = app(StartCheckoutAction::class)->execute($owner, Actor::query()->findOrFail(1), 'billing@example.test', 'pro', 'monthly', 'https://example.test/success', 'https://example.test/cancel');
        $second = app(StartCheckoutAction::class)->execute($owner, Actor::query()->findOrFail(1), 'billing@example.test', 'pro', 'monthly', 'https://example.test/success', 'https://example.test/cancel');
        expect($second->id)->toBe($first->id)->and(BillingAccount::query()->count())->toBe(1)
            ->and(BillingAccount::query()->sole()->getAttribute('tenant_id'))->toBeNull()
            ->and(app(BillingAccess::class)->forOwner($owner)->state)->toBe('free');
        $other = new OwnerReference($owner->type, $owner->storage, 'int', '8');
        expect(fn () => app(BillingAccess::class)->forOwner($other))->toThrow(OwnershipViolation::class);
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->findOrFail(1), function (): void {
        expect(BillingAccount::query()->count())->toBe(0);
    });
    expect(fn () => BillingAccount::query()->get())->toThrow(OwnershipViolation::class);
});

it('rejects the legacy public billing adapter even inside an admitted native owner scope', function (): void {
    app()->register(LegacyBillingServiceProvider::class);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function (): void {
        expect(fn () => app(Nvl\Billing\Compatibility\Services\BillingAccess::class)->forTenant(new TenantId('1bc8245c-81fe-4ffb-b90a-99088939ed5e')))
            ->toThrow(OwnershipViolation::class);
    });
});

it('creates billing factories from native owner scope without a tenant model and guards inherited factory parents', function (): void {
    $account = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () {
        $account = BillingAccount::factory()->create();
        $subscription = BillingSubscription::factory()->forAccount($account)->create();
        $item = BillingSubscriptionItem::factory()->forSubscription($subscription)->create();
        expect($item->getAttribute('owner_scope_key'))->toBe($account->getAttribute('owner_scope_key'));

        return $account;
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->findOrFail(1), function () use ($account): void {
        expect(fn () => BillingSubscription::factory()->forAccount($account)->create())->toThrow(OwnershipViolation::class);
    });
});
