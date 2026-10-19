<?php

declare(strict_types=1);

namespace Nvl\Billing\Ownership;

use Nvl\Billing\Models\BillingAccount;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Services\OwnershipConstraintInspector;

/** Requires the live immutable customer identity and unique primary billing relationship. @internal */
final readonly class BillingIntegrityGuard
{
    /** Retain source inspection capabilities without importing the optional enforcing runtime. */
    public function __construct(private OwnershipReadiness $readiness, private OwnershipConstraintInspector $constraints) {}

    /** Require actual source constraints before financial effects or verified correlation lookup. */
    public function assertReady(): void
    {
        $this->readiness->assertActive('billing.accounts');
        $model = new BillingAccount;
        $this->constraints->assertPresent($model->getConnection(), $model->getTable(), 'update', 'assigned:'.hash('sha256', json_encode(['stripe_id'], JSON_THROW_ON_ERROR)));
        $this->constraints->assertUniqueIndexes($model->getConnection(), $model->getTable(), [['owner_scope_key'], ['stripe_id']]);
    }
}
