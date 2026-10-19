<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Ownership\BillingIntegrityGuard;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipContextResolver;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/** Validates explicit billing subjects against fresh admitted native ownership. @internal */
final readonly class BillingOwnerBoundary
{
    /** Retain neutral ownership contracts rather than a directory or lifecycle dependency. */
    public function __construct(private OwnershipContextResolver $context, private OwnershipResourceRegistry $resources, private BillingIntegrityGuard $integrity) {}

    /** Require the complete native owner identity and active actual source. */
    public function assertOwner(OwnerReference $owner): void
    {
        if ($this->resources->get('billing.accounts')->ownership->strategy === 'unscoped'
            || $this->context->current()->snapshot()->owner != $owner) {
            throw new OwnershipViolation('Billing requires the selected owner to be explicitly admitted.');
        }
        $this->integrity->assertReady();
    }

    /** Bind the management actor argument to the actual admitted producer. */
    public function assertActor(OwnerReference $owner, Authenticatable $actor): void
    {
        $this->assertOwner($owner);
        $id = $actor->getAuthIdentifier();
        if (! is_string($id) && ! is_int($id)) {
            throw new OwnershipViolation('Billing actors require a stable scalar identity.');
        }
        $principal = $this->context->current()->snapshot()->principal;
        if ($principal !== ['kind' => 'actor', 'type' => $actor::class, 'id' => (string) $id]) {
            throw new OwnershipViolation('Billing actor and admitted producer must match.');
        }
    }
}
