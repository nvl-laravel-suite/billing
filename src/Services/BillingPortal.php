<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Contracts\BillingPortalContract;
use Nvl\Billing\Enums\BillingResponseCode;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Creates a portal entry for an authorized tenant billing administrator.
 *
 * @api
 */
final readonly class BillingPortal implements BillingPortalContract
{
    /** Inject permission, tenant, and Stripe boundaries. */
    public function __construct(
        private BillingOwnerBoundary $ownership,
        private BillingManagementAccess $management,
        private BillingGateway $gateway,
    ) {}

    /** Return a hosted portal URL without exposing another owner's customer. */
    public function url(OwnerReference $owner, Authenticatable $actor, string $returnUrl): string
    {
        if (config('nvl-billing.enabled') !== true) {
            throw BillingException::because(BillingResponseCode::FeatureDisabled, 'Billing is disabled.');
        }

        $this->management->assertCanManage($actor, $owner);
        $this->ownership->assertActor($owner, $actor);
        $account = BillingAccount::query()->where('owner_scope_key', $owner->scopeKey())->first();

        if ($account === null || $account->stripe_id === null) {
            throw BillingException::because(BillingResponseCode::SubscriptionConflict, 'The tenant has no Stripe billing customer.');
        }

        return $this->gateway->portal($account, $returnUrl);
    }
}
