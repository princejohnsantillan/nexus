<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Jobs\ConfirmPaymentInBackground;
use App\Models\Payment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakePayMongo;

/**
 * Run the next job on the database queue that is due.
 */
function workConfirmations(): void
{
    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);
}

it('confirms the payment on the database queue', function (): void {
    config(['queue.default' => 'database']);
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create();
    $payMongo->paid($payment, 'gcash');

    ConfirmPaymentInBackground::dispatch($payment->id);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);

    workConfirmations();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('asks PayMongo again later, three times in all, then leaves the payment to the reconciliation', function (): void {
    config(['queue.default' => 'database']);
    $this->freezeSecond();
    $payMongo = FakePayMongo::fake()->respondTo('read', FakePayMongo::failure(503));
    $payment = Payment::factory()->create();

    ConfirmPaymentInBackground::dispatch($payment->id);

    workConfirmations();
    expect(DB::table('jobs')->sole())
        ->attempts->toBe(1)
        ->available_at->toBe(now()->getTimestamp() + 30);

    $this->travel(30)->seconds();
    workConfirmations();
    expect(DB::table('jobs')->sole())
        ->attempts->toBe(2)
        ->available_at->toBe(now()->getTimestamp() + 120);

    $this->travel(120)->seconds();
    workConfirmations();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($payMongo->requests())->toHaveCount(3)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('confirms on a later try once PayMongo answers', function (): void {
    config(['queue.default' => 'database']);
    $payMongo = FakePayMongo::fake()->respondTo('read', FakePayMongo::failure(503));
    $payment = Payment::factory()->create();
    $payMongo->paid($payment);

    ConfirmPaymentInBackground::dispatch($payment->id);
    workConfirmations();

    $payMongo->respondNormallyTo('read');
    $this->travel(30)->seconds();
    workConfirmations();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('is queued once per payment, while it waits to try again too', function (): void {
    config(['queue.default' => 'database']);
    FakePayMongo::fake()->respondTo('read', FakePayMongo::failure(503));
    $payment = Payment::factory()->create();

    ConfirmPaymentInBackground::dispatch($payment->id);
    ConfirmPaymentInBackground::dispatch($payment->id);
    expect(DB::table('jobs')->count())->toBe(1);

    workConfirmations();
    ConfirmPaymentInBackground::dispatch($payment->id);

    expect(DB::table('jobs')->count())->toBe(1);
});

it('skips a payment deleted meanwhile', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create();
    $payment->user->delete();

    ConfirmPaymentInBackground::dispatch($payment->id);

    expect($payMongo->requests())->toBe([]);
});
