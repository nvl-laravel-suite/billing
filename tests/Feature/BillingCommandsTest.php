<?php

declare(strict_types=1);
it('reports a healthy enabled billing configuration', function (): void {
    billingNativeRun(function () {
        config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'price_pro_month']]);
        $this->artisan('nvl:billing:doctor', ['--strict' => true])->assertSuccessful();
    });
});
it('fails strict doctor checks for an invalid price catalog without exposing credentials', function (): void {
    billingNativeRun(function () {
        config()->set('nvl-billing.prices', ['pro' => ['monthly' => 'invalid_price']]);
        $this->artisan('nvl:billing:doctor', ['--strict' => true, '--format' => 'json'])->expectsOutputToContain('"price_catalog":false')->assertFailed();
        $this->artisan('nvl:billing:doctor', ['--format' => 'yaml'])->assertFailed();
    });
});

it('preserves adopted financial constraints when lifecycle storage is already current', function (): void {
    (require __DIR__.'/../../database/migrations/billing/2026_11_01_080624_nvl_billing_complete_account_lifecycle_storage.php')->up();

    billingNativeRun(function (): void {
        $this->artisan('nvl:billing:doctor', ['--strict' => true, '--format' => 'json'])
            ->expectsOutputToContain('"ownership_adoption":true')
            ->assertSuccessful();
    });
});
