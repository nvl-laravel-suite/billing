<?php

declare(strict_types=1);

namespace Nvl\Billing\Compatibility;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipScope;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;

/** Preserves historical explicitly guarded Mail access only before native adoption.
 * @implements Scope<Model>
 *
 * @internal
 */
final readonly class LegacyBillingModelScope implements Scope
{
    /** Never let an old configuration bypass a compiled or persisted native partition. */
    public static function permitsLegacy(Model $model): bool
    {
        if (config('nvl-billing.compatibility.tenancy') !== true || config('nvl-tenancy.enabled') !== true) {
            return false;
        }
        $resources = Container::getInstance()->make(OwnershipResourceRegistry::class);
        $key = null;
        foreach ($resources->all() as $resource) {
            if ($resource->model === $model::class) {
                if ($resource->ownership->strategy !== 'unscoped') {
                    return false;
                }
                $key = $resource->key;
                break;
            }
        }
        if ($key === null) {
            $resources->assertUnclaimed($model);
        }
        (new PersistedOwnershipStorage)->assertUnadopted($model->getConnection(), resource: $key, physicalTable: $model->getTable());

        return true;
    }

    /** @param Builder<covariant Model> $builder */
    public function apply(Builder $builder, Model $model): void
    {
        if (! self::permitsLegacy($model)) {
            (new OwnershipScope)->apply($builder, $model);
        }
    }
}
