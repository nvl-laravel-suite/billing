<?php

declare(strict_types=1);

namespace Nvl\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;

/**
 * Builds BillingSubscription fixture rows and their declared package parents.
 *
 * @extends Factory<BillingSubscription>
 *
 * @api
 */
final class BillingSubscriptionFactory extends Factory
{
    protected $model = BillingSubscription::class;

    /**
     * Prepare native parent and owner facts after Laravel expands relationships.
     *
     * @internal
     */
    public function configure(): static
    {
        $expandRelationships = true;

        return $this->state(function () use (&$expandRelationships): array {
            $expandRelationships = $this->expandRelationships;

            return [];
        })->afterMaking(function (BillingSubscription $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('billing_account_id') !== null) {
                $parent = BillingAccount::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('billing_account_id')));
                FactoryGuard::parent($parent, $model);
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<BillingSubscription>, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_account_id' => BillingAccount::factory(),
            'type' => 'default',
            'stripe_id' => 'sub_'.$this->faker->unique()->uuid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_factory',
            'quantity' => 1,
        ];
    }

    /**
     * Associate an admitted persisted BillingAccount parent.
     *
     * @api
     */
    public function forAccount(BillingAccount $parent): static
    {
        FactoryGuard::parent($parent, new BillingSubscription);

        return $this->state([
            'billing_account_id' => $parent->getKey(),
        ]);
    }
}
