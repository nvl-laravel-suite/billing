<?php

declare(strict_types=1);

namespace Nvl\Billing\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\Models\Tenant;

/**
 * Builds BillingAccount fixture rows and their declared package parents.
 *
 * @extends Factory<BillingAccount>
 *
 * @api
 */
final class BillingAccountFactory extends Factory
{
    protected $model = BillingAccount::class;

    /**
     * Prepare native parent and owner facts after Laravel expands relationships.
     *
     * @internal
     */
    public function configure(): static
    {
        $expandRelationships = true;

        return $this->state(function () use (&$expandRelationships): array {
            $expandRelationships = $this->expandRelationships;

            return [];
        })->afterMaking(function (BillingAccount $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            $tenantIdValue = $model->getAttribute('tenant_id');
            if (! is_string($tenantIdValue)) {
                throw new InvalidArgumentException('Billing fixtures require a persisted tenant.');
            }
            $tenant = Tenant::query()->findOrFail($tenantIdValue);
            if ($tenant->getConnection() !== $model->getConnection() || $tenant->status !== TenantStatus::Active) {
                throw new InvalidArgumentException('Billing fixtures require an active tenant on the Billing connection.');
            }
            if (config('nvl-tenancy.enabled') === true) {
                $tenantId = new TenantId($tenantIdValue);
                $container = Container::getInstance();
                if ($container->make(TenantContext::class)->requireTenant()->value !== $tenantId->value
                    || $container->make(TenantDirectory::class)->find($tenantId)->status !== TenantStatus::Active) {
                    throw new InvalidArgumentException('Billing fixtures require the currently admitted tenant.');
                }
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<BillingAccount>, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => config('nvl-tenancy.enabled') === true
                ? Container::getInstance()->make(TenantContext::class)->snapshot()->tenantId?->value
                : Tenant::factory(),
            'email' => $this->faker->safeEmail(),
            'name' => $this->faker->company(),
        ];
    }

    /** Associate a persisted native tenant without activating its context.
     *
     * @api
     */
    public function forTenant(Tenant $tenant): static
    {
        if (! $tenant->exists || ! is_string($tenant->getKey())
            || $tenant->getRawOriginal($tenant->getKeyName()) !== $tenant->getKey()
            || $tenant->getConnection() !== (new BillingAccount)->getConnection()) {
            throw new InvalidArgumentException('Billing fixtures require a persisted tenant on their connection.');
        }

        return $this->state(['tenant_id' => $tenant->getKey()]);
    }
}
