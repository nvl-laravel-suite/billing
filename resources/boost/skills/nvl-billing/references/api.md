# Public API: nvl/billing

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\Billing\Contracts\BillingGateway`
- `Nvl\Billing\Contracts\BillingManagementAccess`
- `Nvl\Billing\Contracts\SubscriptionReader`
- `Nvl\Billing\Database\Factories\BillingAccountFactory`
- `Nvl\Billing\Database\Factories\BillingSubscriptionFactory`
- `Nvl\Billing\Database\Factories\BillingSubscriptionItemFactory`
- `Nvl\Billing\Enums\BillingResponseCode`
- `Nvl\Billing\Events\BillingAccountChanged`
- `Nvl\Billing\Events\BillingCheckoutStarted`
- `Nvl\Billing\Events\BillingSubscriptionChanged`
- `Nvl\Billing\Exceptions\BillingException`
- `Nvl\Billing\Models\BillingAccount`
- `Nvl\Billing\Models\BillingSubscription`
- `Nvl\Billing\Models\BillingSubscriptionItem`
- `Nvl\Billing\Services\BillingAccess`
- `Nvl\Billing\Services\BillingCheckout`
- `Nvl\Billing\Services\BillingContacts`
- `Nvl\Billing\Services\BillingModelRegistry`
- `Nvl\Billing\Services\BillingPortal`
- `Nvl\Billing\Services\BillingReconciler`
- `Nvl\Billing\Services\BillingWebhooks`
- `Nvl\Billing\Testing\FakeBillingGateway`
- `Nvl\Billing\Testing\FakeSubscriptionReader`

## Nvl\Billing\Contracts\BillingGateway

```text
interface Nvl\Billing\Contracts\BillingGateway
public abstract function checkout(Nvl\Billing\Models\BillingAccount $account, string $price, int $trialDays, string $successUrl, string $cancelUrl, Carbon\CarbonImmutable $expiresAt, string $attemptId): array
@return CheckoutSession
resolved @return array{id: string, url: string, expiresAt: Carbon\CarbonImmutable}
public abstract function portal(Nvl\Billing\Models\BillingAccount $account, string $returnUrl): string
public abstract function updateCustomer(Nvl\Billing\Models\BillingAccount $account, string $name, string $email): void
```

## Nvl\Billing\Contracts\BillingManagementAccess

```text
interface Nvl\Billing\Contracts\BillingManagementAccess
public abstract function assertCanManage(Illuminate\Contracts\Auth\Authenticatable $actor, Nvl\Support\Ownership\ValueObjects\OwnerReference $owner): void
```

## Nvl\Billing\Contracts\SubscriptionReader

```text
interface Nvl\Billing\Contracts\SubscriptionReader
public abstract function forCustomer(string $customerId): array
@return list<array<string, mixed>>
```

## Nvl\Billing\Database\Factories\BillingAccountFactory

```text
final class Nvl\Billing\Database\Factories\BillingAccountFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<BillingAccount>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Billing\Models\BillingAccount>
public function definition(): array
@return array<model-property<BillingAccount>, mixed>
resolved @return array<model-property<Nvl\Billing\Models\BillingAccount>, mixed>
public function modelName(): string
@return class-string<BillingAccount>
resolved @return class-string<Nvl\Billing\Models\BillingAccount>
```

## Nvl\Billing\Database\Factories\BillingSubscriptionFactory

```text
final class Nvl\Billing\Database\Factories\BillingSubscriptionFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<BillingSubscription>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Billing\Models\BillingSubscription>
public function definition(): array
@return array<model-property<BillingSubscription>, mixed>
resolved @return array<model-property<Nvl\Billing\Models\BillingSubscription>, mixed>
public function forAccount(Nvl\Billing\Models\BillingAccount $parent): static
public function modelName(): string
@return class-string<BillingSubscription>
resolved @return class-string<Nvl\Billing\Models\BillingSubscription>
```

## Nvl\Billing\Database\Factories\BillingSubscriptionItemFactory

```text
final class Nvl\Billing\Database\Factories\BillingSubscriptionItemFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<BillingSubscriptionItem>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Billing\Models\BillingSubscriptionItem>
public function definition(): array
@return array<model-property<BillingSubscriptionItem>, mixed>
resolved @return array<model-property<Nvl\Billing\Models\BillingSubscriptionItem>, mixed>
public function forSubscription(Nvl\Billing\Models\BillingSubscription $parent): static
```

## Nvl\Billing\Enums\BillingResponseCode

```text
enum Nvl\Billing\Enums\BillingResponseCode implements BackedEnum, Nvl\Support\Contracts\ResponseCode, UnitEnum: string
public const Nvl\Billing\Enums\BillingResponseCode BindingRequired=Nvl\Billing\Enums\BillingResponseCode::BindingRequired="binding_required"
public const Nvl\Billing\Enums\BillingResponseCode CheckoutConflict=Nvl\Billing\Enums\BillingResponseCode::CheckoutConflict="checkout_conflict"
public const Nvl\Billing\Enums\BillingResponseCode FeatureDisabled=Nvl\Billing\Enums\BillingResponseCode::FeatureDisabled="feature_disabled"
public const Nvl\Billing\Enums\BillingResponseCode InvalidConfiguration=Nvl\Billing\Enums\BillingResponseCode::InvalidConfiguration="invalid_configuration"
public const Nvl\Billing\Enums\BillingResponseCode OperationConflict=Nvl\Billing\Enums\BillingResponseCode::OperationConflict="operation_conflict"
public const Nvl\Billing\Enums\BillingResponseCode OperationFailed=Nvl\Billing\Enums\BillingResponseCode::OperationFailed="operation_failed"
public const Nvl\Billing\Enums\BillingResponseCode OwnerInactive=Nvl\Billing\Enums\BillingResponseCode::OwnerInactive="owner_inactive"
public const Nvl\Billing\Enums\BillingResponseCode PaymentStateInvalid=Nvl\Billing\Enums\BillingResponseCode::PaymentStateInvalid="payment_state_invalid"
public const Nvl\Billing\Enums\BillingResponseCode ProviderIdentityMismatch=Nvl\Billing\Enums\BillingResponseCode::ProviderIdentityMismatch="provider_identity_mismatch"
public const Nvl\Billing\Enums\BillingResponseCode ProviderPayloadInvalid=Nvl\Billing\Enums\BillingResponseCode::ProviderPayloadInvalid="provider_payload_invalid"
public const Nvl\Billing\Enums\BillingResponseCode ReconciliationRequired=Nvl\Billing\Enums\BillingResponseCode::ReconciliationRequired="reconciliation_required"
public const Nvl\Billing\Enums\BillingResponseCode RefundBalanceExceeded=Nvl\Billing\Enums\BillingResponseCode::RefundBalanceExceeded="refund_balance_exceeded"
public const Nvl\Billing\Enums\BillingResponseCode StorageUnavailable=Nvl\Billing\Enums\BillingResponseCode::StorageUnavailable="storage_unavailable"
public const Nvl\Billing\Enums\BillingResponseCode SubscriptionConflict=Nvl\Billing\Enums\BillingResponseCode::SubscriptionConflict="subscription_conflict"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Billing\Events\BillingAccountChanged

```text
final readonly class Nvl\Billing\Events\BillingAccountChanged implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $accountId, ?string $tenantId, string $operation, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly string $accountId
public readonly string $operation
public readonly int $schemaVersion
public readonly ?string $tenantId
```

## Nvl\Billing\Events\BillingCheckoutStarted

```text
final readonly class Nvl\Billing\Events\BillingCheckoutStarted implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $accountId, ?string $tenantId, string $attemptId, string $sessionId, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly string $accountId
public readonly string $attemptId
public readonly int $schemaVersion
public readonly string $sessionId
public readonly ?string $tenantId
```

## Nvl\Billing\Events\BillingSubscriptionChanged

```text
final readonly class Nvl\Billing\Events\BillingSubscriptionChanged implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $subscriptionId, string $accountId, ?string $tenantId, ?string $previousStatus, string $currentStatus, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly string $accountId
public readonly string $currentStatus
public readonly ?string $previousStatus
public readonly int $schemaVersion
public readonly string $subscriptionId
public readonly ?string $tenantId
```

## Nvl\Billing\Exceptions\BillingException

```text
class Nvl\Billing\Exceptions\BillingException extends DomainException implements Nvl\Support\Contracts\RespondableException
public static function because(Nvl\Billing\Enums\BillingResponseCode $code, string $diagnosticMessage, array $publicContext=[], ?Throwable $previous=null): self
@param array<string, mixed> $publicContext
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Billing\Models\BillingAccount

```text
class Nvl\Billing\Models\BillingAccount extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Billing\Definitions\Tables\BillingTables::Accounts
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getForeignKey(): string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Billing\Models\BillingAccount>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function subscriptions(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<BillingSubscription, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Billing\Models\BillingSubscription, $this>
@property string $email
@property string $id
@property (string | null) $name
@property (string | null) $owner_reference
@property string $owner_scope_key
@property (string | null) $pending_checkout_attempt_id
@property (CarbonImmutable | null) $pending_checkout_expires_at
resolved @property (Carbon\CarbonImmutable | null) $pending_checkout_expires_at
@property (string | null) $pending_checkout_price
@property (string | null) $pending_checkout_request_hash
@property (string | null) $pending_checkout_session_id
@property (int | null) $pending_checkout_trial_days
@property (string | null) $pending_checkout_url
@property (CarbonImmutable | null) $provider_deleted_at
resolved @property (Carbon\CarbonImmutable | null) $provider_deleted_at
@property (string | null) $stripe_id
@property (string | null) $tenant_id
@property (Carbon | null) $trial_consumed_at
resolved @property (Illuminate\Support\Carbon | null) $trial_consumed_at
```

## Nvl\Billing\Models\BillingSubscription

```text
class Nvl\Billing\Models\BillingSubscription extends Laravel\Cashier\Subscription implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Billing\Definitions\Tables\BillingTables::Subscriptions
public function billingAccount(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<BillingAccount, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Billing\Models\BillingAccount, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getForeignKey(): string
public function getTable(): string
public function items(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<BillingSubscriptionItem, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Nvl\Billing\Models\BillingSubscriptionItem, $this>
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Billing\Models\BillingSubscription>
public function owner(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<BillingAccount, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Billing\Models\BillingAccount, $this>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property string $billing_account_id
@property (Carbon | null) $created_at
resolved @property (Illuminate\Support\Carbon | null) $created_at
@property (Carbon | null) $ends_at
resolved @property (Illuminate\Support\Carbon | null) $ends_at
@property string $id
@property (int | null) $quantity
@property string $stripe_id
@property (string | null) $stripe_price
@property string $stripe_status
@property string $type
```

## Nvl\Billing\Models\BillingSubscriptionItem

```text
final class Nvl\Billing\Models\BillingSubscriptionItem extends Laravel\Cashier\SubscriptionItem implements Nvl\Support\Ownership\Contracts\Ownable
public const string TABLE=\Nvl\Billing\Definitions\Tables\BillingTables::SubscriptionItems
public function billingSubscription(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<BillingSubscription, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Billing\Models\BillingSubscription, $this>
public function delete(): ?bool
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Billing\Models\BillingSubscriptionItem>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
public function subscription(): Illuminate\Database\Eloquent\Relations\BelongsTo
@return BelongsTo<BillingSubscription, $this>
resolved @return Illuminate\Database\Eloquent\Relations\BelongsTo<Nvl\Billing\Models\BillingSubscription, $this>
@property string $billing_subscription_id
@property string $id
@property (int | null) $quantity
@property string $stripe_id
@property string $stripe_price
@property string $stripe_product
```

## Nvl\Billing\Services\BillingAccess

```text
final readonly class Nvl\Billing\Services\BillingAccess
public function __construct(Nvl\Billing\Catalog\PlanCatalog $catalog, Nvl\Billing\Services\BillingOwnerBoundary $ownership): mixed
public function forOwner(Nvl\Support\Ownership\ValueObjects\OwnerReference $owner): array
@return BillingAccessState
resolved @return array{plan: (string | null), state: string, features: list<string>, limits: array<string, int>}
```

## Nvl\Billing\Services\BillingCheckout

```text
final readonly class Nvl\Billing\Services\BillingCheckout
public function __construct(Nvl\Billing\Services\BillingOwnerBoundary $ownership, Nvl\Billing\Contracts\BillingManagementAccess $management, Nvl\Billing\Catalog\PlanCatalog $catalog, Nvl\Billing\Contracts\BillingGateway $gateway, Nvl\Billing\Services\BillingReturnUrls $returnUrls, ?Nvl\Billing\Services\BillingEvents $events=null): mixed
public function start(Nvl\Support\Ownership\ValueObjects\OwnerReference $owner, Illuminate\Contracts\Auth\Authenticatable $actor, string $billingEmail, string $plan, string $interval, string $successUrl, string $cancelUrl): array
@return CheckoutSession
resolved @return array{id: string, url: string, expiresAt: Carbon\CarbonImmutable}
@throws DomainException
@throws InvalidArgumentException
```

## Nvl\Billing\Services\BillingContacts

```text
final readonly class Nvl\Billing\Services\BillingContacts
public function __construct(Nvl\Billing\Services\BillingOwnerBoundary $ownership, Nvl\Billing\Contracts\BillingManagementAccess $management, Nvl\Billing\Contracts\BillingGateway $gateway, ?Nvl\Billing\Services\BillingEvents $events=null): mixed
public function update(Nvl\Support\Ownership\ValueObjects\OwnerReference $owner, Illuminate\Contracts\Auth\Authenticatable $actor, string $name, string $email): Nvl\Billing\Models\BillingAccount
```

## Nvl\Billing\Services\BillingModelRegistry

```text
final class Nvl\Billing\Services\BillingModelRegistry
public function __construct(Illuminate\Contracts\Config\Repository $configuration, Nvl\Support\OwnerRegistry $identities): mixed
public function accountModel(): string
@return class-string<BillingAccount>
resolved @return class-string<Nvl\Billing\Models\BillingAccount>
public function prepare(): void
public function subscriptionModel(): string
@return class-string<BillingSubscription>
resolved @return class-string<Nvl\Billing\Models\BillingSubscription>
```

## Nvl\Billing\Services\BillingPortal

```text
final readonly class Nvl\Billing\Services\BillingPortal
public function __construct(Nvl\Billing\Services\BillingOwnerBoundary $ownership, Nvl\Billing\Contracts\BillingManagementAccess $management, Nvl\Billing\Contracts\BillingGateway $gateway, Nvl\Billing\Services\BillingReturnUrls $returnUrls): mixed
public function url(Nvl\Support\Ownership\ValueObjects\OwnerReference $owner, Illuminate\Contracts\Auth\Authenticatable $actor, string $returnUrl): string
```

## Nvl\Billing\Services\BillingReconciler

```text
final readonly class Nvl\Billing\Services\BillingReconciler
public function __construct(Nvl\Billing\Contracts\SubscriptionReader $reader, Nvl\Billing\Services\BillingOwnerBoundary $ownership, Nvl\Billing\Services\BillingSubscriptionSyncer $syncer, Nvl\Billing\Services\BillingAccountStateUpdater $accounts): mixed
public function reconcile(Nvl\Support\Ownership\ValueObjects\OwnerReference $owner): void
```

## Nvl\Billing\Services\BillingWebhooks

```text
final class Nvl\Billing\Services\BillingWebhooks
public function __construct(Nvl\Billing\Services\BillingSubscriptionSyncer $subscriptions, Nvl\Billing\Services\BillingAccountStateUpdater $accounts, Nvl\Billing\Services\BillingCustomerSyncer $customers, Nvl\Billing\Ownership\BillingProviderAdmission $ownership, Nvl\Billing\Contracts\SubscriptionReader $reader): mixed
public function process(string $payload, string $signature): void
```

## Nvl\Billing\Testing\FakeBillingGateway

```text
final class Nvl\Billing\Testing\FakeBillingGateway implements Nvl\Billing\Contracts\BillingGateway
public function assertCalled(string $method, ?Closure $predicate=null, int $times=1): void
@param (Closure(FakeCall): bool | null) $predicate
resolved @param (Closure(Nvl\Support\Testing\FakeCall): bool | null) $predicate
public function calls(?string $method=null): array
@return list<FakeCall>
resolved @return list<Nvl\Support\Testing\FakeCall>
public function checkout(Nvl\Billing\Models\BillingAccount $account, string $price, int $trialDays, string $successUrl, string $cancelUrl, Carbon\CarbonImmutable $expiresAt, string $attemptId): array
@return CheckoutSession
resolved @return array{id: string, url: string, expiresAt: Carbon\CarbonImmutable}
public static function fake(Illuminate\Contracts\Container\Container $container): self
public function portal(Nvl\Billing\Models\BillingAccount $account, string $returnUrl): string
public function updateCustomer(Nvl\Billing\Models\BillingAccount $account, string $name, string $email): void
public function willReturn(string $method, mixed $value): static
public function willThrow(string $method, Throwable $exception): static
```

## Nvl\Billing\Testing\FakeSubscriptionReader

```text
final class Nvl\Billing\Testing\FakeSubscriptionReader implements Nvl\Billing\Contracts\SubscriptionReader
public function assertCalled(string $method, ?Closure $predicate=null, int $times=1): void
@param (Closure(FakeCall): bool | null) $predicate
resolved @param (Closure(Nvl\Support\Testing\FakeCall): bool | null) $predicate
public function calls(?string $method=null): array
@return list<FakeCall>
resolved @return list<Nvl\Support\Testing\FakeCall>
public static function fake(Illuminate\Contracts\Container\Container $container): self
public function forCustomer(string $customerId): array
@return list<array<string, mixed>>
public function willReturn(string $method, mixed $value): static
public function willThrow(string $method, Throwable $exception): static
```
