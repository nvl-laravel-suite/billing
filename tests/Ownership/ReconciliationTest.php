<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Enums\BillingResponseCode;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Tests\OwnerScopeTestCase;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnerScopeTestCase::class);

it('continues an admitted owner worklist after an operational failure and returns a safe failure summary', function (bool $domainFailure): void {
    foreach ([7 => 'cus_failed', 8 => 'cus_successful'] as $ownerId => $customer) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($ownerId), Actor::query()->findOrFail(1), fn () => BillingAccount::query()->forceCreate([
            'id' => sprintf('00000000-0000-4000-8000-%012d', $ownerId),
            'email' => 'billing@example.test', 'stripe_id' => $customer,
        ]));
    }
    config(['nvl-ownership.system_operations' => ['billing.reconcile']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void {}
    });
    $reader = new class($domainFailure) implements SubscriptionReader
    {
        /** @var list<string> */
        public array $customers = [];

        public function __construct(private bool $domainFailure) {}

        public function forCustomer(string $customerId): array
        {
            $this->customers[] = $customerId;
            if ($customerId === 'cus_failed') {
                throw $this->domainFailure
                    ? BillingException::because(BillingResponseCode::ReconciliationRequired, 'private-provider-detail')
                    : new RuntimeException('private-provider-detail');
            }

            return [];
        }
    };
    app()->instance(SubscriptionReader::class, $reader);

    expect(Artisan::call('nvl:billing:reconcile'))->toBe(1)
        ->and($reader->customers)->toBe(['cus_failed', 'cus_successful'])
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe(['succeeded' => 1, 'failed' => 1])
        ->and(Artisan::output())->not->toContain('private-provider-detail', 'cus_failed', 'billing@example.test')
        ->and(app(OwnershipContext::class)->snapshot()->owner)->toBeNull();
})->with([false, true]);

it('does not downgrade owner admission failures to operational reconciliation failures', function (): void {
    foreach ([7, 8] as $ownerId) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($ownerId), Actor::query()->findOrFail(1), fn () => BillingAccount::query()->forceCreate([
            'id' => sprintf('00000000-0000-4000-8000-%012d', $ownerId),
            'email' => 'billing@example.test', 'stripe_id' => 'cus_'.$ownerId,
        ]));
    }
    config(['nvl-ownership.system_operations' => ['billing.reconcile']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void
        {
            throw new OwnershipViolation;
        }
    });
    $reader = Mockery::mock(SubscriptionReader::class);
    $reader->shouldNotReceive('forCustomer');
    app()->instance(SubscriptionReader::class, $reader);

    expect(fn () => Artisan::call('nvl:billing:reconcile'))->toThrow(OwnershipViolation::class)
        ->and(app(OwnershipContext::class)->snapshot()->owner)->toBeNull();
});

it('reconciles explicit owner worklists only after named maintenance authorization', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), fn () => BillingAccount::query()->forceCreate(['email' => 'billing@example.test', 'stripe_id' => 'cus_reconcile']));
    $reader = new class implements SubscriptionReader
    {
        /** @var list<string> */
        public array $customers = [];

        public function forCustomer(string $customerId): array
        {
            $this->customers[] = $customerId;

            return [];
        }
    };
    app()->instance(SubscriptionReader::class, $reader);
    expect(fn () => Artisan::call('nvl:billing:reconcile'))->toThrow(OwnershipViolation::class);
    expect($reader->customers)->toBe([]);
    config(['nvl-ownership.system_operations' => ['billing.reconcile']]);
    app()->bind(OwnerSystemAccess::class, fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void
        {
            if ($operation !== 'billing.reconcile' || $owner->getKey() !== 7) {
                throw new OwnershipViolation;
            }
        }
    });
    expect(Artisan::call('nvl:billing:reconcile'))->toBe(0)->and($reader->customers)->toBe(['cus_reconcile'])
        ->and(app(OwnershipContext::class)->snapshot()->owner)->toBeNull();
});
