<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Nvl\Billing\Contracts\BillingGateway;
use Nvl\Billing\Models\BillingAccount;
use Nvl\Billing\Testing\FakeBillingGateway;
use Nvl\Billing\ValueObjects\CheckoutSession;
use Nvl\Support\Testing\UnscriptedFakeCall;

it('installs a host injected gateway with explicit native results and zero external effects', function (): void {
    $account = BillingAccount::factory()->withoutParents()->make();
    $before = $account->getAttributes();
    $expiry = CarbonImmutable::parse('2026-10-07T12:00:00Z');
    $session = new CheckoutSession('cs_explicit', 'https://checkout.example.test/session', $expiry);
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Notification::fake();
    Bus::fake();
    Queue::fake();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $fake = FakeBillingGateway::fake($this->app);
    $fake->willReturn('checkout', $session)->willReturn('checkout', $session)->willReturn('portal', 'https://portal.example.test/customer')->willReturn('updateCustomer', null);
    $arguments = ['account' => $account, 'price' => 'price_explicit', 'trialDays' => 14, 'successUrl' => 'https://host.example.test/success', 'cancelUrl' => 'https://host.example.test/cancel', 'expiresAt' => $expiry, 'attemptId' => 'attempt-stable'];
    $result = $this->app->call(fn (BillingGateway $gateway) => $gateway->checkout(...$arguments));
    expect($result)->toBe($session)->and($fake->checkout(...$arguments))->toBe($session)
        ->and($fake->portal($account, 'https://host.example.test/return'))->toBe('https://portal.example.test/customer');
    $fake->updateCustomer($account, 'Host billing name', 'billing@example.test');
    expect($fake->calls('checkout')[0]->arguments)->toBe($arguments)
        ->and($fake->calls('checkout')[1]->arguments)->toBe($arguments)
        ->and($fake->calls('portal')[0]->arguments)->toBe(['account' => $account, 'returnUrl' => 'https://host.example.test/return'])
        ->and($fake->calls('updateCustomer')[0]->arguments)->toBe(['account' => $account, 'name' => 'Host billing name', 'email' => 'billing@example.test'])
        ->and($account->getAttributes())->toBe($before)->and($queries)->toBe([]);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
    $other = new Container;
    $otherFake = FakeBillingGateway::fake($other);
    expect($otherFake)->not->toBe($fake)->and($otherFake->calls())->toBe([])
        ->and($this->app->make(BillingGateway::class))->toBe($fake);
});

it('records exhausted and throwing attempts and rejects incompatible native return values', function (): void {
    $fake = new FakeBillingGateway;
    $account = BillingAccount::factory()->withoutParents()->make();
    $expiry = CarbonImmutable::parse('2026-10-07T12:00:00Z');
    $failure = new RuntimeException('explicit failure');
    $fake->willThrow('updateCustomer', $failure)->willReturn('portal', null)->willReturn('checkout', 'invented URL');
    try {
        $fake->updateCustomer($account, 'Name', 'mail@example.test');
    } catch (RuntimeException $caught) {
        expect($caught)->toBe($failure);
    }
    expect(fn () => $fake->updateCustomer($account, 'Name', 'mail@example.test'))->toThrow(UnscriptedFakeCall::class);
    expect(fn () => $fake->portal($account, 'return'))->toThrow(TypeError::class);
    expect(fn () => $fake->checkout($account, 'price', 0, 'success', 'cancel', $expiry, 'attempt'))->toThrow(TypeError::class);
    expect(array_column($fake->calls(), 'method'))->toBe(['updateCustomer', 'updateCustomer', 'portal', 'checkout']);
    $fake->assertCalled('updateCustomer', times: 2);
});
