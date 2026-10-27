<?php

declare(strict_types=1);

namespace Nvl\Billing\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Models\BillingSubscription;
use Nvl\Support\Models\ConfiguredModelRegistration;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/**
 * Freezes the configured financial roots while retaining native schema and source identity.
 *
 * @api
 */
final class BillingModelRegistry
{
    /** @var ConfiguredModelRegistration<BillingAccount> */
    private readonly ConfiguredModelRegistration $account;

    /** @var ConfiguredModelRegistration<BillingSubscription> */
    private readonly ConfiguredModelRegistration $subscription;

    /** Retain deployment configuration and the canonical native identity registry. */
    public function __construct(Repository $configuration, OwnerRegistry $identities)
    {
        $this->account = new ConfiguredModelRegistration(
            $configuration, $identities, 'nvl-billing.models.account', BillingAccount::class, 'billing.accounts',
            static function (string $class): void {
                if ($class::ownershipDefinition()->strategy !== 'unscoped') {
                    throw new OwnershipConfigurationInvalid('Financial model declarations retain the package resource ownership strategy.');
                }
            },
        );
        $this->subscription = new ConfiguredModelRegistration(
            $configuration, $identities, 'nvl-billing.models.subscription', BillingSubscription::class, 'billing.subscriptions',
            static function (string $class): void {
                if ($class::ownershipDefinition()->strategy !== 'unscoped') {
                    throw new OwnershipConfigurationInvalid('Financial model declarations retain the package resource ownership strategy.');
                }
            },
        );
    }

    /** Freeze both root classes before compiling native resource and provider identities. @internal */
    public function prepare(): void
    {
        $this->account->prepare();
        $this->subscription->prepare();
    }

    /** Resolve and validate the deployment's effective account model.
     * @return class-string<BillingAccount>
     */
    public function accountModel(): string
    {
        $class = $this->account->modelClass();
        $this->assertStorage(new $class, new BillingAccount);

        return $class;
    }

    /** Resolve the configured root from native framework entry points.
     * @internal
     *
     * @return class-string<BillingAccount>
     */
    public static function accountClass(): string
    {
        return Container::getInstance()->make(self::class)->accountModel();
    }

    /** Construct the configured root on its package-owned source.
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function newAccount(array $attributes = []): BillingAccount
    {
        $class = self::accountClass();

        return new $class($attributes);
    }

    /** Build a canonical native root query.
     * @internal
     *
     * @return Builder<BillingAccount>
     */
    public static function accountQuery(): Builder
    {
        return self::newAccount()->newQuery();
    }

    /** Resolve and validate the deployment's effective subscription model.
     * @return class-string<BillingSubscription>
     */
    public function subscriptionModel(): string
    {
        $class = $this->subscription->modelClass();
        $this->assertStorage(new $class, new BillingSubscription);

        return $class;
    }

    /** Resolve the configured root from native framework entry points.
     * @internal
     *
     * @return class-string<BillingSubscription>
     */
    public static function subscriptionClass(): string
    {
        return Container::getInstance()->make(self::class)->subscriptionModel();
    }

    /** Construct the configured root on its package-owned source.
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function newSubscription(array $attributes = []): BillingSubscription
    {
        $class = self::subscriptionClass();

        return new $class($attributes);
    }

    /** Build a canonical native root query.
     * @internal
     *
     * @return Builder<BillingSubscription>
     */
    public static function subscriptionQuery(): Builder
    {
        return self::newSubscription()->newQuery();
    }

    /** Reject split financial storage before technical children or effects can escape its transaction. */
    private function assertStorage(Model $configured, Model $base): void
    {
        if ($configured->getConnection() !== $base->getConnection() || $configured->getTable() !== $base->getTable()
            || $configured->getForeignKey() !== $base->getForeignKey()) {
            throw new OwnershipConfigurationInvalid('Configured financial roots must retain their package table, actual connection and native foreign key.');
        }
    }
}
