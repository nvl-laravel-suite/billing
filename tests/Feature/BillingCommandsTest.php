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
