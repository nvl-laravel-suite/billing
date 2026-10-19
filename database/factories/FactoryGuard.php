<?php

declare(strict_types=1);

namespace Nvl\Billing\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Billing\Compatibility\Factories\LegacyBillingFactoryGuard;
use Nvl\Billing\Compatibility\LegacyBillingModelScope;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/** Checks actual persisted billing parents without a tenant lifecycle dependency. @internal */
final readonly class FactoryGuard
{
    /** Validate a stable native scalar key. */
    public static function identifier(mixed $key): int|string
    {
        if ((! is_string($key) && ! is_int($key)) || $key === '') {
            throw new InvalidArgumentException('Billing fixture identifiers require a nonempty native scalar.');
        }

        return $key;
    }

    /** Require an unchanged actual parent in the admitted source and owner. */
    public static function parent(Model $parent, Model $child): void
    {
        if (LegacyBillingModelScope::permitsLegacy($parent)) {
            LegacyBillingFactoryGuard::parent($parent, $child);

            return;
        }
        if ($parent->getConnection() !== $child->getConnection()) {
            throw new InvalidArgumentException('Billing factories require their actual parent source.');
        }
        $container = Container::getInstance();
        $resource = $container->make(OwnershipResourceRegistry::class)->forModel($parent);
        $container->make(OwnershipBoundary::class)->assertRecord($parent, $resource->key);
    }
}
