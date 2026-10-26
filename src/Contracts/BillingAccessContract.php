<?php

declare(strict_types=1);

namespace Nvl\Billing\Contracts;

use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Resolves the owner's effective billing features and limits.
 *
 * @phpstan-type BillingAccessState array{plan: string|null, state: string, features: list<string>, limits: array<string, int>}
 *
 * @api
 */
interface BillingAccessContract
{
    /** Return one owner's effective access.
     * @return BillingAccessState
     */
    public function forOwner(OwnerReference $owner): array;
}
