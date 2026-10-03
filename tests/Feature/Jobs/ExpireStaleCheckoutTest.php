<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Jobs\ExpireStaleCheckout;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePayMongo;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));
});

it('expires a pending checkout on PayMongo and in Nexus', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create();
    $payMongo->open($payment);

    ExpireStaleCheckout::dispatch($payment->id);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Expired)
        ->and($payMongo->statusOf($payment))->toBe('expired');
});

it('confirms a checkout that was paid after all instead of expiring it', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->monthly()->create();
    $payMongo->paid($payment);

    ExpireStaleCheckout::dispatch($payment->id);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($user->fresh()->pro_until?->toIso8601String())->toBe('2026-11-03T06:00:00+00:00');
});

it('leaves a payment that is no longer pending, or no longer there, without asking PayMongo', function (Closure $payment): void {
    $payMongo = FakePayMongo::fake();

    ExpireStaleCheckout::dispatch($payment());

    expect($payMongo->requests())->toBe([]);
})->with([
    'paid' => [fn (): int => Payment::factory()->paid()->create()->id],
    'expired' => [fn (): int => Payment::factory()->expired()->create()->id],
    'deleted with its user' => [function (): int {
        $payment = Payment::factory()->create();
        $payment->user->delete();

        return $payment->id;
    }],
]);

it('keeps the checkout pending when PayMongo can\'t be reached, without failing', function (): void {
    $payMongo = FakePayMongo::fake()->respondTo('read', FakePayMongo::unreachable());
    $payment = Payment::factory()->create();

    ExpireStaleCheckout::dispatch($payment->id);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payMongo->requests())->toHaveCount(1);
});

it('is queued once per payment', function (): void {
    Queue::fake([ExpireStaleCheckout::class]);
    $payment = Payment::factory()->create();

    ExpireStaleCheckout::dispatch($payment->id);
    ExpireStaleCheckout::dispatch($payment->id);
    ExpireStaleCheckout::dispatch(Payment::factory()->create()->id);

    Queue::assertPushedTimes(ExpireStaleCheckout::class, 2);
});
