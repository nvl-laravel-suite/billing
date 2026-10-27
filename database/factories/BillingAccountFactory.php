<?php

declare(strict_types=1);

namespace Nvl\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Services\BillingModelRegistry;

/**
 * Builds a captured native billing account without manufacturing a tenant.
 *
 * @extends Factory<BillingAccount>
 *
 * @api
 */
final class BillingAccountFactory extends Factory
{
    protected $model = BillingAccount::class;

    /** @return class-string<BillingAccount> */
    public function modelName(): string
    {
        return BillingModelRegistry::accountClass();
    }

    /** @return array<model-property<BillingAccount>,mixed> */
    public function definition(): array
    {
        return ['email' => $this->faker->safeEmail(), 'name' => $this->faker->company()];
    }
}
