<?php

declare(strict_types=1);

use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Models\BillingAccount;

it('reconciles one selected tenant from the command line', function (): void {
    $tenant = '1bc8245c-81fe-4ffb-b90a-99088939ed5e';
    $account = BillingAccount::query()->forceCreate([
        'tenant_id' => $tenant,
        'email' => 'billing@example.test',
        'stripe_id' => 'cus_test',
    ]);
    $subscription = $account->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_orphan',
        'stripe_status' => 'active',
    ]);
    app()->instance(SubscriptionReader::class, new class implements SubscriptionReader
    {
        public function forCustomer(string $customerId): array
        {
            return [];
        }
    });

    $this->artisan('nvl:billing:reconcile', ['--tenant' => $tenant])->assertSuccessful();

    expect($subscription->fresh()->stripe_status)->toBe('canceled');
});

it('reports a healthy enabled billing configuration', function (): void {
    config()->set('billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);

    $this->artisan('nvl:billing:doctor', ['--strict' => true])->assertSuccessful();
});
