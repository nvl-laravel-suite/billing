<?php

declare(strict_types=1);

use Nvl\Billing\Testing\FakeSubscriptionReader;
use Nvl\Support\Testing\UnscriptedFakeCall;

test('subscription reader consumes explicit lists without reordering native customer facts', function (): void {
    $facts = [['id' => 'sub_42', 'items' => [['price' => 'price_42']], 'active' => true], ['id' => 'sub_old', 'active' => false]];
    $reader = (new FakeSubscriptionReader)->willReturn('forCustomer', $facts)->willReturn('forCustomer', []);

    expect($reader->forCustomer('cus_42'))->toBe($facts)
        ->and($reader->forCustomer('cus_42'))->toBe([])
        ->and(fn (): array => $reader->forCustomer('cus_42'))->toThrow(UnscriptedFakeCall::class)
        ->and($reader->calls('forCustomer')[0]->arguments)->toBe(['customerId' => 'cus_42']);
    $reader->assertCalled('forCustomer', times: 3);
});

test('subscription reader rejects invalid top level and field shapes', function (mixed $value): void {
    $reader = (new FakeSubscriptionReader)->willReturn('forCustomer', $value);

    expect(fn (): array => $reader->forCustomer('cus_42'))->toThrow(TypeError::class);
})->with([
    'null' => [null],
    'associative result' => [['associative' => []]],
    'object row' => [[new stdClass]],
    'numeric field' => [[[0 => 'numeric-field']]],
]);

test('subscription reader records failure attempts and retains the original throwable', function (): void {
    $failure = new RuntimeException('remote failure');
    $reader = (new FakeSubscriptionReader)->willThrow('forCustomer', $failure);

    try {
        $reader->forCustomer('cus_42');
        test()->fail('Scripted reader failure must propagate.');
    } catch (RuntimeException $exception) {
        expect($exception)->toBe($failure);
    }
    expect($reader->calls('forCustomer')[0]->arguments)->toBe(['customerId' => 'cus_42']);
});
