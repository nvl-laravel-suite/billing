<?php

declare(strict_types=1);

use Nvl\Billing\Catalog\PlanCatalog;

it('resolves only configured plan prices in both directions', function (): void {
    $catalog = new PlanCatalog([
        'starter' => ['monthly' => 'price_starter_month', 'yearly' => 'price_starter_year'],
        'pro' => ['monthly' => 'price_pro_month'],
    ]);

    expect($catalog->priceFor('starter', 'yearly'))->toBe('price_starter_year')
        ->and($catalog->planForPrice('price_pro_month'))->toBe('pro')
        ->and($catalog->planForPrice('price_unknown'))->toBeNull();
});

it('rejects unknown plan variants and duplicate price ownership', function (): void {
    $catalog = new PlanCatalog(['starter' => ['monthly' => 'price_starter_month']]);

    expect(fn () => $catalog->priceFor('starter', 'yearly'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PlanCatalog([
            'starter' => ['monthly' => 'price_shared'],
            'pro' => ['monthly' => 'price_shared'],
        ]))->toThrow(InvalidArgumentException::class);
});
