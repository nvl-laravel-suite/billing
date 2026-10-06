<?php

declare(strict_types=1);

namespace Nvl\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Billing\Models\BillingSubscriptionItem;

/**
 * Builds BillingSubscriptionItem fixture rows and their declared package parents.
 *
 * @extends Factory<BillingSubscriptionItem>
 *
 * @api
 */
final class BillingSubscriptionItemFactory extends Factory
{
    protected $model = BillingSubscriptionItem::class;

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
        })->afterMaking(function (BillingSubscriptionItem $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('billing_subscription_id') !== null) {
                $parent = BillingSubscription::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('billing_subscription_id')));
                FactoryGuard::parent($parent, $model);
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<BillingSubscriptionItem>, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_subscription_id' => BillingSubscription::factory(),
            'stripe_id' => 'si_'.$this->faker->unique()->uuid(),
            'stripe_product' => 'prod_factory',
            'stripe_price' => 'price_factory',
            'quantity' => 1,
        ];
    }

    /**
     * Associate an admitted persisted BillingSubscription parent.
     *
     * @api
     */
    public function forSubscription(BillingSubscription $parent): static
    {
        FactoryGuard::parent($parent, new BillingSubscriptionItem);

        return $this->state([
            'billing_subscription_id' => $parent->getKey(),
        ]);
    }
}
