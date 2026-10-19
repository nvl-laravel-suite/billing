<?php

declare(strict_types=1);

namespace Nvl\Billing\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Nvl\Billing\Definitions\Tables\BillingTables;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\ValueObjects\OwnershipMapping;

/** Adopts a nonempty account with a pending pre-cutover provider attempt and historical receipt. */
abstract class LegacyOwnerScopeTestCase extends OwnerScopeTestCase
{
    public const string Account = '6d19a317-e28a-4cc9-8b4d-3ec786e9034e';

    public const string Attempt = '5ce12b8c-de72-4a31-bbb1-415a0b2541f8';

    public const string Tenant = '1bc8245c-81fe-4ffb-b90a-99088939ed5e';

    /** @return list<OwnershipMapping> */
    protected function adoptionMappings(): array
    {
        DB::table(BillingTables::get(BillingTables::Accounts))->insert([
            'id' => self::Account, 'tenant_id' => self::Tenant, 'email' => 'legacy@example.test', 'stripe_id' => 'cus_cutover',
            'pending_checkout_attempt_id' => self::Attempt, 'pending_checkout_price' => 'price_pro',
            'pending_checkout_expires_at' => CarbonImmutable::now()->addHour(), 'pending_checkout_trial_days' => 0,
            'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]);
        DB::table(BillingTables::get(BillingTables::WebhookEvents))->insert(['stripe_event_id' => 'evt_cutover', 'type' => 'customer.updated', 'processed_at' => CarbonImmutable::now()]);
        $owner = $this->app->make(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(7));

        return [new OwnershipMapping('billing.accounts', self::Account, $owner, legacyTenantId: self::Tenant),
            new OwnershipMapping('billing.webhooks', 'evt_cutover', null)];
    }
}
