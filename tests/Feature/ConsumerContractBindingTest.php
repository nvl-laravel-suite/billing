<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Nvl\Billing\Actions\StartCheckoutAction;
use Nvl\Billing\Actions\UpdateBillingContactAction;
use Nvl\Billing\Catalog\PlanCatalog;
use Nvl\Billing\Contracts\BillingAccessContract;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Contracts\BillingManagementAccess;
use Nvl\Billing\Contracts\BillingPortalContract;
use Nvl\Billing\Contracts\StartCheckoutContract;
use Nvl\Billing\Contracts\SubscriptionReader;
use Nvl\Billing\Contracts\UpdateBillingContactContract;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Providers\BillingServiceProvider;
use Nvl\Billing\Services\BillingAccess;
use Nvl\Billing\Services\BillingPortal;
use Nvl\Billing\Services\DenyBillingManagementAccess;
use Nvl\Billing\Services\StripeBillingGateway;
use Nvl\Billing\Services\StripeSubscriptionReader;
use Nvl\Billing\Tests\BillingTestCase;
use Nvl\Billing\Tests\Fixtures\BillingConsumerWorkflow;
use Nvl\Billing\ValueObjects\BillingSnapshot;
use Nvl\Billing\ValueObjects\CheckoutSession;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses(BillingTestCase::class);
}

dataset('billing consumer contracts', [
    'checkout' => [StartCheckoutContract::class, StartCheckoutAction::class, 'execute', [
        ['tenant', TenantId::class], ['actor', Authenticatable::class], ['billingEmail', 'string'],
        ['plan', 'string'], ['interval', 'string'], ['successUrl', 'string'], ['cancelUrl', 'string'],
    ], CheckoutSession::class],
    'contact' => [UpdateBillingContactContract::class, UpdateBillingContactAction::class, 'execute', [
        ['tenant', TenantId::class], ['actor', Authenticatable::class], ['name', 'string'], ['email', 'string'],
    ], BillingAccount::class],
    'access' => [BillingAccessContract::class, BillingAccess::class, 'forTenant', [['tenant', TenantId::class]], BillingSnapshot::class],
    'portal' => [BillingPortalContract::class, BillingPortal::class, 'url', [
        ['tenant', TenantId::class], ['actor', Authenticatable::class], ['returnUrl', 'string'],
    ], 'string'],
]);

it('preserves each complete native signature and method documentation', function (string $contract, string $implementation, string $method, array $parameters, string $result): void {
    expect(interface_exists($contract))->toBeTrue();
    $interface = new ReflectionClass($contract);
    $concrete = new ReflectionClass($implementation);
    $nativeMethods = array_values(array_filter($concrete->getMethods(ReflectionMethod::IS_PUBLIC), static fn (ReflectionMethod $member): bool => ! $member->isConstructor() && ! $member->isStatic()));

    expect($concrete->implementsInterface($contract))->toBeTrue()
        ->and($concrete->isFinal())->toBeTrue()
        ->and($concrete->isReadOnly())->toBeTrue()
        ->and($concrete->getAttributes())->toBe([])
        ->and($interface->getAttributes())->toBe([])
        ->and($interface->getDocComment())->toContain('@api')
        ->and(array_map(static fn (ReflectionMethod $member): string => $member->getName(), $interface->getMethods()))->toBe([$method])
        ->and(array_map(static fn (ReflectionMethod $member): string => $member->getName(), $nativeMethods))->toBe([$method]);

    $native = $concrete->getMethod($method);
    $declared = $interface->getMethod($method);
    foreach ([$native, $declared] as $member) {
        expect((string) $member->getReturnType())->toBe($result)
            ->and($member->returnsReference())->toBeFalse()
            ->and($member->getAttributes())->toBe([])
            ->and(count($member->getParameters()))->toBe(count($parameters));

        foreach ($member->getParameters() as $position => $parameter) {
            expect([$parameter->getName(), (string) $parameter->getType()])->toBe($parameters[$position])
                ->and($parameter->isPassedByReference())->toBeFalse()
                ->and($parameter->isVariadic())->toBeFalse()
                ->and($parameter->isDefaultValueAvailable())->toBeFalse()
                ->and($parameter->getAttributes())->toBe([]);
        }
    }

    expect($declared->getDocComment())->toBe($native->getDocComment());
})->with('billing consumer contracts');

it('retains the original concrete constructors and private promoted dependencies', function (): void {
    $expected = [
        StartCheckoutAction::class => [['tenants', TenantDirectory::class], ['management', BillingManagementAccess::class], ['catalog', PlanCatalog::class], ['gateway', BillingGateway::class]],
        UpdateBillingContactAction::class => [['tenants', TenantDirectory::class], ['management', BillingManagementAccess::class], ['gateway', BillingGateway::class]],
        BillingAccess::class => [['catalog', PlanCatalog::class]],
        BillingPortal::class => [['tenants', TenantDirectory::class], ['management', BillingManagementAccess::class], ['gateway', BillingGateway::class]],
    ];

    foreach ($expected as $implementation => $parameters) {
        $concrete = new ReflectionClass($implementation);
        $constructor = $concrete->getConstructor() ?? throw new LogicException('A concrete constructor is required.');
        expect($constructor->getNumberOfRequiredParameters())->toBe(count($parameters));
        expect($concrete->newInstanceArgs(array_map(static fn (array $parameter): object => app($parameter[1]), $parameters)))->toBeInstanceOf($implementation);
        foreach (array_slice($constructor->getParameters(), 0, count($parameters)) as $position => $parameter) {
            $property = $concrete->getProperty($parameter->getName());
            expect([$parameter->getName(), (string) $parameter->getType()])->toBe($parameters[$position])
                ->and($parameter->isPromoted())->toBeTrue()
                ->and($parameter->isDefaultValueAvailable())->toBeFalse()
                ->and($property->isPrivate())->toBeTrue()
                ->and($property->isReadOnly())->toBeTrue();
        }
    }
});

it('resolves native contract and concrete defaults transiently in independent applications', function (string $contract, string $implementation): void {
    expect(interface_exists($contract))->toBeTrue();
    $first = billingContractApplication($this->app);

    try {
        $first->register(BillingServiceProvider::class);
        $resolved = $first->make($contract);
        $concrete = $first->make($implementation);
        $catalog = $first->make(PlanCatalog::class);
        expect($resolved)->toBeInstanceOf($implementation)
            ->not->toBe($first->make($contract))
            ->not->toBe($concrete)
            ->and($concrete)->toBeInstanceOf($implementation)
            ->and($catalog)->toBe($first->make(PlanCatalog::class));

        $replacement = Mockery::mock($contract);
        $first->instance($contract, $replacement);
        $second = billingContractApplication($this->app);
        try {
            $second->register(BillingServiceProvider::class);
            $next = $second->make($contract);
            expect($next)->toBeInstanceOf($implementation)
                ->not->toBe($resolved)
                ->not->toBe($replacement)
                ->not->toBe($second->make($contract))
                ->and($second->make(PlanCatalog::class))->not->toBe($catalog)
                ->and($first->make($contract))->toBe($replacement);
        } finally {
            $second->flush();
        }
    } finally {
        restoreBillingContractApplication($this->app, $first);
    }
})->with('billing consumer contracts');

it('keeps the existing gateway reader and authorization defaults conditional', function (): void {
    $consumer = billingContractApplication($this->app);
    try {
        $consumer->register(BillingServiceProvider::class);
        foreach ([BillingGateway::class => StripeBillingGateway::class, SubscriptionReader::class => StripeSubscriptionReader::class, BillingManagementAccess::class => DenyBillingManagementAccess::class] as $contract => $implementation) {
            expect($consumer->make($contract))->toBeInstanceOf($implementation);
            $substitute = Mockery::mock($contract);
            $consumer->instance($contract, $substitute);
            $consumer->register(BillingServiceProvider::class, force: true);
            expect($consumer->make($contract))->toBe($substitute);
        }
    } finally {
        restoreBillingContractApplication($this->app, $consumer);
    }
});

it('preserves a host instance installed before discovery through real constructor injection', function (string $contract): void {
    expect(interface_exists($contract))->toBeTrue();
    $substitute = Mockery::mock($contract);
    $consumer = billingContractApplication($this->app);
    $consumer->instance($contract, $substitute);
    try {
        $consumer->register(BillingServiceProvider::class);
        $host = $consumer->make(BillingConsumerWorkflow::class);
        expect($host->dependency($contract))->toBe($substitute);
        $consumer->register(BillingServiceProvider::class, force: true);
        expect($consumer->make(BillingConsumerWorkflow::class)->dependency($contract))->toBe($substitute);
    } finally {
        restoreBillingContractApplication($this->app, $consumer);
    }
})->with('billing consumer contracts');

it('preserves lazy host closures installed before discovery', function (string $contract): void {
    expect(interface_exists($contract))->toBeTrue();
    $substitute = Mockery::mock($contract);
    $calls = 0;
    $consumer = billingContractApplication($this->app);
    $consumer->bind($contract, static function () use ($substitute, &$calls): object {
        $calls++;

        return $substitute;
    });
    try {
        $consumer->register(BillingServiceProvider::class);
        expect($calls)->toBe(0);
        expect($consumer->make(BillingConsumerWorkflow::class)->dependency($contract))->toBe($substitute)
            ->and($calls)->toBe(1);
        $consumer->register(BillingServiceProvider::class, force: true);
        expect($calls)->toBe(1);
        expect($consumer->make(BillingConsumerWorkflow::class)->dependency($contract))->toBe($substitute)
            ->and($calls)->toBe(2);
    } finally {
        restoreBillingContractApplication($this->app, $consumer);
    }
})->with('billing consumer contracts');

it('delivers real result handles through prebound and late host workflows without package effects', function (bool $late): void {
    foreach ([StartCheckoutContract::class, UpdateBillingContactContract::class, BillingAccessContract::class, BillingPortalContract::class] as $contract) {
        expect(interface_exists($contract))->toBeTrue();
    }
    $tenant = new TenantId('1bc8245c-81fe-4ffb-b90a-99088939ed5e');
    $actor = new GenericUser(['id' => 'billing-host-actor']);
    $checkout = new CheckoutSession('cs_host', 'https://checkout.example.test/host', CarbonImmutable::parse('2030-01-01T00:00:00Z'));
    $snapshot = new BillingSnapshot('pro', 'active', ['reports'], ['projects' => 17]);
    $account = new BillingAccount;
    $account->forceFill(['id' => 'account-host', 'tenant_id' => $tenant->value, 'name' => 'Host Billing', 'email' => 'billing@example.test']);
    $start = Mockery::mock(StartCheckoutContract::class);
    $start->shouldReceive('execute')->once()->with($tenant, $actor, 'billing@example.test', 'pro', 'monthly', 'https://app.example.test/success', 'https://app.example.test/cancel')->andReturn($checkout);
    $contact = Mockery::mock(UpdateBillingContactContract::class);
    $contact->shouldReceive('execute')->once()->with($tenant, $actor, 'Host Billing', 'billing@example.test')->andReturn($account);
    $access = Mockery::mock(BillingAccessContract::class);
    $access->shouldReceive('forTenant')->once()->with($tenant)->andReturn($snapshot);
    $portal = Mockery::mock(BillingPortalContract::class);
    $portal->shouldReceive('url')->once()->with($tenant, $actor, 'https://app.example.test/billing')->andReturn('https://portal.example.test/host');
    $substitutes = [StartCheckoutContract::class => $start, UpdateBillingContactContract::class => $contact, BillingAccessContract::class => $access, BillingPortalContract::class => $portal];
    $connection = DB::connection();
    $consumer = billingContractApplication($this->app);
    try {
        if (! $late) {
            foreach ($substitutes as $contract => $substitute) {
                $consumer->instance($contract, $substitute);
            }
        }
        $consumer->register(BillingServiceProvider::class);
        if ($late) {
            $original = $consumer->make(BillingConsumerWorkflow::class);
            foreach ([StartCheckoutContract::class => StartCheckoutAction::class, UpdateBillingContactContract::class => UpdateBillingContactAction::class, BillingAccessContract::class => BillingAccess::class, BillingPortalContract::class => BillingPortal::class] as $contract => $implementation) {
                expect($original->dependency($contract))->toBeInstanceOf($implementation);
                $consumer->instance($contract, $substitutes[$contract]);
                expect($original->dependency($contract))->toBeInstanceOf($implementation);
            }
        }

        $resolutions = 0;
        foreach ([StartCheckoutAction::class, UpdateBillingContactAction::class, BillingAccess::class, BillingPortal::class, PlanCatalog::class, BillingGateway::class, SubscriptionReader::class, BillingManagementAccess::class, TenantDirectory::class, StripeBillingGateway::class, StripeSubscriptionReader::class, 'db', 'db.connection', 'filesystem'] as $dependency) {
            $consumer->bind($dependency, static function () use (&$resolutions, $dependency): never {
                $resolutions++;

                throw new LogicException('Real Billing work must not resolve: '.$dependency);
            });
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($consumer);
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $host = $consumer->make(BillingConsumerWorkflow::class);
        $outcome = $host->prepare($tenant, $actor);

        expect($outcome['checkout'])->toBe($checkout)
            ->and($outcome['checkout']->id)->toBe('cs_host')
            ->and($outcome['checkout']->url)->toBe('https://checkout.example.test/host')
            ->and($outcome['checkout']->expiresAt->toIso8601String())->toBe('2030-01-01T00:00:00+00:00')
            ->and($outcome['contact'])->toBe($account)
            ->and($outcome['contact']->name)->toBe('Host Billing')
            ->and($outcome['contact']->email)->toBe('billing@example.test')
            ->and($outcome['contact']->exists)->toBeFalse()
            ->and($outcome['access'])->toBe($snapshot)
            ->and($outcome['access']->plan)->toBe('pro')
            ->and($outcome['access']->state)->toBe('active')
            ->and($outcome['reports'])->toBeTrue()
            ->and($outcome['projects'])->toBe(17)
            ->and($outcome['portal'])->toBe('https://portal.example.test/host')
            ->and($resolutions)->toBe(0)
            ->and($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
        restoreBillingContractApplication($this->app, $consumer);
    }
})->with(['before discovery' => false, 'after native resolution' => true]);

/** Create a native application that exercises package configuration and providers. */
function billingContractApplication(Application $original): Application
{
    $consumer = new Application($original->basePath());
    $configuration = new Repository($original->make('config')->all());
    $configuration->set('nvl-billing.adoption.cashier_routes', false);
    $consumer->instance('config', $configuration);
    $consumer->instance('env', 'testing');
    $consumer->register(FilesystemServiceProvider::class);

    return $consumer;
}

/** Restore framework globals after an isolated provider application. */
function restoreBillingContractApplication(Application $original, Application $consumer): void
{
    Container::setInstance($original);
    Facade::setFacadeApplication($original);
    Facade::clearResolvedInstances();
    $consumer->flush();
}
