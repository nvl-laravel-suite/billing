<?php

declare(strict_types=1);

namespace Nvl\Billing\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Services\BillingReconciler;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Contracts\OwnershipSystemRunner;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/** Reconciles explicit native billing owners under fresh named maintenance admission. */
final class BillingReconcileCommand extends Command
{
    protected $signature = 'nvl:billing:reconcile {--owner= : Complete native owner reference JSON}';

    protected $description = 'Reconcile owner subscriptions from Stripe';

    /** Enumerate protected owner identity only and admit each operation before reading financial data. */
    public function handle(BillingReconciler $reconciler, OwnershipSystemRunner $runner, OwnershipReadiness $readiness): int
    {
        $readiness->assertActive('billing.accounts');
        $selected = $this->option('owner');
        if (is_string($selected) && $selected !== '') {
            $owner = OwnerReference::fromJson($selected);
            $runner->runSystem($owner, 'billing.reconcile', fn () => $reconciler->reconcile($owner));

            return self::SUCCESS;
        }
        $model = new BillingAccount;
        foreach ($model->getConnection()->table($model->getTable())->useWritePdo()->whereNotNull('stripe_id')->orderBy('id')->select(['id', 'owner_scope_key', 'owner_reference'])->lazyById(100) as $row) {
            if (! is_string($row->owner_reference)) {
                throw new OwnershipViolation;
            }
            $owner = OwnerReference::fromJson($row->owner_reference);
            if ($row->owner_scope_key !== $owner->scopeKey()) {
                throw new OwnershipViolation;
            }
            $runner->runSystem($owner, 'billing.reconcile', fn () => $reconciler->reconcile($owner));
        }

        return self::SUCCESS;
    }
}
