<?php

declare(strict_types=1);

namespace Nvl\Billing\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Contracts\UpdateBillingContactContract;
use Nvl\Billing\Enums\BillingResponseCode;
use Nvl\Billing\Exceptions\BillingException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Services\BillingEvents;
use Nvl\Billing\Services\BillingOwnerBoundary;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Updates the owner-owned contact used for Stripe billing correspondence.
 *
 * @api
 */
final readonly class UpdateBillingContactAction implements UpdateBillingContactContract
{
    /** Inject tenant, authorization, and Stripe boundaries. */
    public function __construct(
        private BillingOwnerBoundary $ownership,
        private BillingManagementAccess $management,
        private BillingGateway $gateway,
        private ?BillingEvents $events = null,
    ) {}

    /** Change the contact only after the host authorizes this owner. */
    public function execute(OwnerReference $owner, Authenticatable $actor, string $name, string $email): BillingAccount
    {
        if (config('nvl-billing.enabled') !== true) {
            throw BillingException::because(BillingResponseCode::FeatureDisabled, 'Billing is disabled.');
        }

        $this->management->assertCanManage($actor, $owner);
        $this->ownership->assertActor($owner, $actor);

        $name = trim($name);
        $email = trim($email);
        if ($name === '' || strlen($name) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            throw new InvalidArgumentException('A valid billing contact name and email are required.');
        }

        $account = BillingAccount::query()->where('owner_scope_key', $owner->scopeKey())->first();
        if ($account === null) {
            return (new BillingAccount)->getConnection()->transaction(function () use ($name, $email): BillingAccount {
                $created = BillingAccount::query()->create(['name' => $name, 'email' => $email]);
                ($this->events ?? BillingEvents::current())->accountChanged($created, 'created');

                return $created;
            });
        }

        if ($account->stripe_id !== null) {
            $this->gateway->updateCustomer($account, $name, $email);
        }

        $account->getConnection()->transaction(function () use ($account, $name, $email): void {
            $account->setRawAttributes($account->newQuery()->whereKey($account->getKey())->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $account->forceFill(['name' => $name, 'email' => $email]);
            $changed = $account->isDirty(['name', 'email']);
            $account->save();
            if ($changed) {
                ($this->events ?? BillingEvents::current())->accountChanged($account, 'contact_updated');
            }
        });

        return $account;
    }
}
