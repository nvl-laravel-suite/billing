<?php

use Nvl\Billing\Compatibility\Services\BillingAccess;

it('rejects the legacy public billing adapter even inside an admitted native owner scope', function (): void {
    app()->register(LegacyBillingServiceProvider::class);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function (): void {
        expect(fn () => app(BillingAccess::class)->forTenant(new TenantId('1bc8245c-81fe-4ffb-b90a-99088939ed5e')))
            ->toThrow(OwnershipViolation::class);
    });
});
