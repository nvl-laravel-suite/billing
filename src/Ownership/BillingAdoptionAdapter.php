<?php

declare(strict_types=1);

namespace Nvl\Billing\Ownership;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipAdoptionStore;
use Nvl\Ownership\Services\OwnershipDatabaseConstraints;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipConstraintInspector;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Adopts exact reviewed Billing records without guessing primary owners from associations.
 *
 * @internal
 */
final readonly class BillingAdoptionAdapter implements OwnershipAdoptionAdapter
{
    /** Retain the canonical services and immutable inputs for this workflow. */
    public function __construct(
        private OwnershipResourceRegistry $resources,
        private OwnershipAdoptionStore $mappings,
        private OwnershipSchema $schema,
        private OwnershipDatabaseConstraints $constraints,
        private OwnershipConstraintInspector $indexes,
    ) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['billing.accounts', 'billing.subscriptions', 'billing.items', 'billing.webhooks'];
    }

    /** Prepare additive partitions and replace only the package's known global receipt identity. */
    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        foreach ($this->resources() as $key) {
            $model = new ($this->resources->get($key)->model);
            $schema = $model->getConnection()->getSchemaBuilder();
            foreach (['tenant_id', 'ownership_key'] as $column) {
                if ($schema->hasColumn($model->getTable(), $column)) {
                    $schema->table($model->getTable(), static fn (Blueprint $table) => ($column === 'tenant_id' ? $table->uuid($column) : $table->string($column, 191))->nullable()->change());
                }
            }
            $this->schema->prepare($key);
        }
        $account = new ($this->resources->get('billing.accounts')->model);
        $accountSchema = $account->getConnection()->getSchemaBuilder();
        if (! $accountSchema->hasColumn($account->getTable(), 'provider_deleted_at')) {
            $accountSchema->table($account->getTable(), static fn (Blueprint $table) => $table->timestamp('provider_deleted_at')->nullable());
        }
        $this->prepareOwnerIndex();
        $this->restoreNativeParents();
    }

    /** Restore the concrete native FK when a legacy adapter replaced it with a tenant composite.
     * @param  list<array{string,string}>  $extraEdges
     */
    private function restoreNativeParents(array $extraEdges = []): void
    {
        foreach ($this->resources() as $key) {
            $definition = $this->resources->get($key);
            if ($definition->ownership->strategy !== 'inherited' || count($definition->parents()) !== 1) {
                continue;
            }
            $model = new $definition->model;
            $relationName = $definition->ownership->relation ?? throw new OwnershipConfigurationInvalid('Inherited ownership requires a concrete parent relation.');
            $relation = $model->{$relationName}();
            if (! $relation instanceof BelongsTo) {
                throw new OwnershipConfigurationInvalid('Inherited ownership requires a canonical native parent relation.');
            }
            if ($relation instanceof MorphTo) {
                continue;
            }
            $parent = new ($this->resources->get($definition->parents()[0])->model);
            $this->restoreNativeForeign($model, $relation->getForeignKeyName(), $parent);
        }
        foreach ($extraEdges as [$resource, $column]) {
            $model = new ($this->resources->get($resource)->model);
            $this->restoreNativeForeign($model, $column, $model);
        }
    }

    /** Preserve existing keys and add only a missing exact native parent constraint. */
    private function restoreNativeForeign(Model $model, string $column, Model $parent): void
    {
        if ($model->getConnection() !== $parent->getConnection()) {
            throw new OwnershipConfigurationInvalid('Inherited package records require the same actual native source.');
        }
        $schema = $model->getConnection()->getSchemaBuilder();
        foreach ($schema->getForeignKeys($model->getTable()) as $foreign) {
            if ($foreign['columns'] === [$column]
                && $foreign['foreign_table'] === $parent->getConnection()->getTablePrefix().$parent->getTable()
                && $foreign['foreign_columns'] === [$parent->getKeyName()]) {
                return;
            }
        }
        $name = 'nvl_native_'.substr(hash('sha256', $model->getTable().$column), 0, 24);
        $schema->table($model->getTable(), static fn (Blueprint $table) => $table->foreign([$column], $name)->references([$parent->getKeyName()])->on($parent->getTable())->restrictOnDelete());
    }

    /** Backfill bounded actual records from explicit immutable per-record mappings. */
    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        $processed = 0;
        do {
            $result = $this->backfillResource($plan, $cursor, $limit - $processed);
            $processed += $result->processed;
            $cursor = $result->nextCursor;
        } while ($cursor !== null && $processed < $limit);

        return new OwnershipBackfillResult($cursor, $processed);
    }

    /** Consume at most one resource page without counting empty tables as progress. */
    private function backfillResource(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        $parts = $cursor === null ? ['0', ''] : explode(':', $cursor, 2);
        if (count($parts) !== 2 || preg_match('/^[0-9]+$/D', $parts[0]) !== 1) {
            throw new OwnershipConfigurationInvalid('Invalid Billing adoption cursor.');
        }
        $keys = $this->resources();
        $position = (int) $parts[0];
        $key = $keys[$position] ?? throw new OwnershipConfigurationInvalid('Invalid Billing adoption resource.');
        $definition = $this->resources->get($key);
        $model = new $definition->model;
        $connection = $model->getConnection();
        $recordKey = $model->getKeyName();
        $rows = $connection->table($model->getTable())->when($parts[1] !== '', static fn ($query) => $query->where($recordKey, '>', $parts[1]))->orderBy($recordKey)->limit($limit)->get();
        $last = '';
        $connection->transaction(function () use ($rows, $key, $plan, $definition, $model, $connection, &$last): void {
            foreach ($rows as $row) {
                if (! is_string($row->{$model->getKeyName()})) {
                    throw new OwnershipConfigurationInvalid('Billing adoption requires canonical UUID records.');
                }
                $mapping = $this->mappings->forRecord($plan, $key, $row->{$model->getKeyName()});
                $owner = $mapping->owner;
                if (isset($row->tenant_id) && $mapping->legacyTenantId !== $row->tenant_id) {
                    throw new OwnershipConfigurationInvalid('Reviewed Billing mappings must preserve the original tenant identity.');
                }
                $nativeOwner = $definition->ownership->foreignKey === null ? null : ($row->{$definition->ownership->foreignKey} ?? null);
                if ($nativeOwner !== null && ((! is_int($nativeOwner) && ! is_string($nativeOwner)) || (string) $nativeOwner !== $owner?->key)) {
                    throw new OwnershipConfigurationInvalid('Billing adoption cannot relabel an existing native primary owner.');
                }
                $attributes = ['owner_scope_key' => $owner?->scopeKey() ?? 'central'];
                $ownership = $definition->ownership;
                if ($ownership->strategy === 'captured') {
                    $attributes['owner_reference'] = $owner === null ? null : json_encode($owner->toArray(), JSON_THROW_ON_ERROR);
                } elseif ($ownership->strategy === 'column') {
                    $attributes[$ownership->foreignKey ?? throw new OwnershipConfigurationInvalid] = $owner?->key;
                } elseif ($ownership->strategy === 'pivot' && $owner !== null) {
                    $connection->table($ownership->table ?? throw new OwnershipConfigurationInvalid)->insertOrIgnore([
                        $ownership->resourceKey ?? throw new OwnershipConfigurationInvalid => $row->{$model->getKeyName()},
                        $ownership->ownerKey ?? throw new OwnershipConfigurationInvalid => $owner->key,
                        'owner_scope_key' => $owner->scopeKey(),
                    ]);
                }
                $connection->table($model->getTable())->where($model->getKeyName(), $row->{$model->getKeyName()})->update($attributes);
                $last = $row->{$model->getKeyName()};
            }
        });
        $next = $rows->count() === $limit ? $position.':'.$last : ($position + 1 < count($keys) ? ($position + 1).':' : null);

        return new OwnershipBackfillResult($next, $rows->count());
    }

    /** Prepare an additive unique owner slot using a source-specific identifier. */
    private function prepareOwnerIndex(): void
    {
        $account = new ($this->resources->get('billing.accounts')->model);
        $schema = $account->getConnection()->getSchemaBuilder();
        $name = $this->indexes->name($account->getConnection(), $account->getTable(), 'index', 'billing-owner');
        if (! $schema->hasIndex($account->getTable(), $name)) {
            $schema->table($account->getTable(), static fn (Blueprint $table) => $table->unique('owner_scope_key', $name));
        }
    }

    /** Verify persisted partitions and the complete canonical domain graph. */
    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        foreach ($this->resources() as $key) {
            $this->schema->verify($key);
        }

        $account = new ($this->resources->get('billing.accounts')->model);
        $this->indexes->assertUniqueIndexes($account->getConnection(), $account->getTable(), [['owner_scope_key'], ['stripe_id']]);

        return new OwnershipVerification([]);
    }

    /** Constrain every native parent and scope edge before activation. */
    public function activate(OwnershipAdoptionPlan $plan): void
    {
        foreach ($this->resources() as $key) {
            $this->schema->constrain($key);
        }
        $account = new ($this->resources->get('billing.accounts')->model);
        $this->constraints->freezeAssigned($account->getConnection(), $account->getTable(), ['stripe_id']);
        $this->prepareOwnerIndex();
    }
}
