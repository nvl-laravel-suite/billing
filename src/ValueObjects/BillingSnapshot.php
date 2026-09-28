<?php

declare(strict_types=1);

namespace Nvl\Billing\ValueObjects;

/** A tenant's resolved subscription and application access at one instant. */
final readonly class BillingSnapshot
{
    /**
     * Preserve an immutable access view for one plan and subscription state.
     *
     * @param  list<string>  $features
     * @param  array<string, int>  $limits
     */
    public function __construct(
        public ?string $plan,
        public string $state,
        private array $features,
        private array $limits,
    ) {}

    /** Return whether the tenant has the named feature. */
    public function allows(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    /** Return the configured limit, or zero when no quota is granted. */
    public function limit(string $name): int
    {
        return $this->limits[$name] ?? 0;
    }
}
