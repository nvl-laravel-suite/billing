<?php

declare(strict_types=1);

namespace Nvl\Billing\Console\Commands;

use DomainException;
use Illuminate\Console\Command;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Services\BillingReconciler;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/** Repairs local billing state from current Stripe subscriptions. */
final class BillingReconcileCommand extends Command
{
    protected $signature = 'nvl:billing:reconcile {--tenant= : Reconcile only this tenant UUID}';

    protected $description = 'Reconcile tenant subscriptions from Stripe';

    /** Reconcile one tenant or page through all Stripe-backed accounts. */
    public function handle(BillingReconciler $reconciler): int
    {
        if (config('billing.enabled') !== true) {
            throw new DomainException('Billing is disabled.');
        }

        $selected = $this->option('tenant');
        if (is_string($selected) && $selected !== '') {
            $reconciler->reconcile(new TenantId($selected));

            return self::SUCCESS;
        }

        BillingAccount::query()->whereNotNull('stripe_id')->select(['id', 'tenant_id'])
            ->chunkById(100, static function ($accounts) use ($reconciler): void {
                foreach ($accounts as $account) {
                    $reconciler->reconcile(new TenantId($account->tenant_id));
                }
            });

        return self::SUCCESS;
    }
}
