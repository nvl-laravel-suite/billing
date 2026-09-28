<?php

declare(strict_types=1);

namespace Nvl\Billing\Catalog;

use InvalidArgumentException;

/**
 * Resolves stable application plan keys to allowlisted Stripe prices.
 */
final readonly class PlanCatalog
{
    /** @var array<string, array<string, string>> */
    private array $plans;

    /** Normalize framework configuration into a validated Stripe Price catalog. */
    public static function fromConfig(mixed $configured): self
    {
        if (! is_array($configured)) {
            throw new InvalidArgumentException('billing.prices must be a plan map.');
        }

        $plans = [];
        foreach ($configured as $plan => $variants) {
            if (! is_string($plan) || ! is_array($variants)) {
                throw new InvalidArgumentException('Each billing plan must contain named variants.');
            }

            $prices = [];
            foreach ($variants as $interval => $price) {
                if (! is_string($interval) || ! is_string($price)) {
                    throw new InvalidArgumentException('Billing variants must map to Stripe Price IDs.');
                }

                $prices[$interval] = $price;
            }

            $plans[$plan] = $prices;
        }

        return new self($plans);
    }

    /**
     * Validate the configured plan and billing interval mappings.
     *
     * @param  array<string, array<string, string>>  $plans
     *
     * @throws InvalidArgumentException When a plan maps ambiguously or has an invalid price
     */
    public function __construct(array $plans)
    {
        $prices = [];

        foreach ($plans as $plan => $intervals) {
            if (preg_match('/^[a-z][a-z0-9_-]*$/', $plan) !== 1 || $intervals === []) {
                throw new InvalidArgumentException('Each billing plan must have a name and at least one price.');
            }

            foreach ($intervals as $interval => $price) {
                if (preg_match('/^[a-z][a-z0-9_-]*$/', $interval) !== 1 || ! str_starts_with($price, 'price_') || isset($prices[$price])) {
                    throw new InvalidArgumentException('Billing prices must be unique Stripe Price IDs.');
                }

                $prices[$price] = true;
            }
        }

        $this->plans = $plans;
    }

    /**
     * Resolve the configured Stripe Price ID for a plan variant.
     *
     * @throws InvalidArgumentException When the variant is unavailable
     */
    public function priceFor(string $plan, string $interval): string
    {
        return $this->plans[$plan][$interval]
            ?? throw new InvalidArgumentException('The requested billing plan variant is unavailable.');
    }

    /** Return the application plan key for a known Stripe Price ID. */
    public function planForPrice(string $price): ?string
    {
        foreach ($this->plans as $plan => $intervals) {
            if (in_array($price, $intervals, true)) {
                return $plan;
            }
        }

        return null;
    }
}
