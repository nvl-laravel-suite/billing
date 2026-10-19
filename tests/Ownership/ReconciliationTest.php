<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Tests\OwnerScopeTestCase;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnerScopeTestCase::class);

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
