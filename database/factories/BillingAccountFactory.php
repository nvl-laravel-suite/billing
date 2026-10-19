<?php

declare(strict_types=1);

namespace Nvl\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Billing\Compatibility\Factories\LegacyBillingAccountFactory;
use Nvl\Billing\Compatibility\LegacyBillingModelScope;
use Nvl\Billing\Models\BillingAccount;

/**
 * Builds a captured native billing account without manufacturing a tenant.
 *
 * @extends Factory<BillingAccount>
 *
 * @api
 */
final class BillingAccountFactory extends Factory
{
    use LegacyBillingAccountFactory;

    protected $model = BillingAccount::class;

    /** Select the explicit pre-adoption factory bridge only when the bridge provider is installed. */
    public function configure(): static
    {
        return LegacyBillingModelScope::permitsLegacy(new BillingAccount) ? $this->configureLegacy() : $this;
    }

    /** @return array<model-property<BillingAccount>,mixed> */
    public function definition(): array
    {
        return LegacyBillingModelScope::permitsLegacy(new BillingAccount) ? $this->legacyDefinition()
            : ['email' => $this->faker->safeEmail(), 'name' => $this->faker->company()];
    }
}
