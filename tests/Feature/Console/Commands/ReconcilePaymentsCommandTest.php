<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakePayMongo;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));
});

it('confirms a checkout paid by someone who never came back', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->monthly()->create(['created_at' => now()->subMinutes(3)]);
    $payMongo->paidByCard($payment, 'visa', '4345');

    $this->artisan('nexus:billing:reconcile')
        ->expectsOutputToContain('Looked at 1 pending payment: 1 paid, 0 expired, 0 still pending.')
        ->assertSuccessful();

    expect($payment->fresh())
        ->status->toBe(PaymentStatus::Paid)
        ->card_last4->toBe('4345');
    expect($user->fresh()->pro_until)->toEqual(CarbonImmutable::parse('2026-11-03 06:00:00', 'UTC'));
});

it('leaves a checkout under 2 minutes old to the return page', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create(['created_at' => now()->subSeconds(119)]);
    $payMongo->paid($payment);

    $this->artisan('nexus:billing:reconcile')
        ->expectsOutputToContain('No pending payments to reconcile.')
        ->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payMongo->requests())->toBe([]);
});

it('leaves an unpaid checkout under 24 hours old open', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create(['created_at' => now()->subHours(23)]);
    $payMongo->open($payment);

    $this->artisan('nexus:billing:reconcile')
        ->expectsOutputToContain('Looked at 1 pending payment: 0 paid, 0 expired, 1 still pending.')
        ->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payMongo->statusOf($payment))->toBe('active')
        ->and(collect($payMongo->requests())->map->method()->all())->toBe(['GET']);
});

it('expires an unpaid checkout over 24 hours old, on PayMongo too', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->create(['created_at' => now()->subHours(24)]);
    $payMongo->open($payment);

    $this->artisan('nexus:billing:reconcile')
        ->expectsOutputToContain('Looked at 1 pending payment: 0 paid, 1 expired, 0 still pending.')
        ->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Expired)
        ->and($payMongo->statusOf($payment))->toBe('expired')
        ->and($user->fresh()->pro_until)->toBeNull();
});

it('confirms rather than expires an old checkout that was paid', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create(['created_at' => now()->subDays(3)]);
    $payMongo->paid($payment);

    $this->artisan('nexus:billing:reconcile')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payMongo->statusOf($payment))->toBe('active')
        ->and(collect($payMongo->requests())->map->method()->all())->toBe(['GET']);
});

it('confirms an old checkout paid while it was being expired', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create(['created_at' => now()->subDays(2)]);
    $payMongo->open($payment)->beforeAnswering('expire', function () use ($payMongo, $payment): void {
        $payMongo->paid($payment);
    });

    $this->artisan('nexus:billing:reconcile')
        ->expectsOutputToContain('Looked at 1 pending payment: 1 paid, 0 expired, 0 still pending.')
        ->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(collect($payMongo->requests())->map->method()->all())->toBe(['GET', 'POST', 'GET']);
});

it('marks expired an old checkout PayMongo expired already', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create(['created_at' => now()->subDays(2)]);
    $payMongo->expired($payment);

    $this->artisan('nexus:billing:reconcile')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Expired);
});

it('marks expired an old payment that never got a checkout, without asking PayMongo', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create(['created_at' => now()->subDays(2), 'checkout_session_id' => null, 'checkout_url' => null]);

    $this->artisan('nexus:billing:reconcile')
        ->expectsOutputToContain('Looked at 1 pending payment: 0 paid, 1 expired, 0 still pending.')
        ->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Expired)
        ->and($payMongo->requests())->toBe([]);
});

it('leaves a payment pending for the next run when PayMongo can\'t be asked', function (string $request, Closure $failure, int $minutesOld): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create(['created_at' => now()->subMinutes($minutesOld)]);
    $payMongo->open($payment)->respondTo($request, $failure);

    $this->artisan('nexus:billing:reconcile')
        ->expectsOutputToContain('Looked at 1 pending payment: 0 paid, 0 expired, 1 still pending.')
        ->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
})->with([
    'reading a recent checkout fails' => ['read', FakePayMongo::failure(500), 10],
    'reading a recent checkout times out' => ['read', FakePayMongo::unreachable(), 10],
    'reading an old checkout fails' => ['read', FakePayMongo::failure(500), 25 * 60],
    'expiring an old checkout fails, and so does reading it again' => ['expire', FakePayMongo::unreachable(), 25 * 60],
]);

it('leaves a payment pending when expiring it fails and PayMongo still has it open', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create(['created_at' => now()->subDays(2)]);
    $payMongo->open($payment)->respondTo('expire', FakePayMongo::failure(500));

    $this->artisan('nexus:billing:reconcile')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payMongo->statusOf($payment))->toBe('active');
});

it('goes on to the next payment when one fails', function (): void {
    $payMongo = FakePayMongo::fake();
    $unknownToPayMongo = Payment::factory()->create(['created_at' => now()->subMinutes(30)]);
    $paid = Payment::factory()->create(['created_at' => now()->subMinutes(20)]);
    $payMongo->paid($paid);

    $this->artisan('nexus:billing:reconcile')
        ->expectsOutputToContain('Looked at 2 pending payments: 1 paid, 0 expired, 1 still pending.')
        ->assertSuccessful();

    expect($unknownToPayMongo->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($paid->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('closes at most 50 stale checkouts a run, oldest first, and leaves the rest for the next', function (): void {
    $payMongo = FakePayMongo::fake();
    $payments = Payment::factory()->count(52)->create(['created_at' => now()->subDays(2)]);
    $payments->each(fn (Payment $payment): FakePayMongo => $payMongo->open($payment));

    $this->artisan('nexus:billing:reconcile')
        ->expectsOutputToContain('Looked at 50 pending payments: 0 paid, 50 expired, 0 still pending.')
        ->expectsOutputToContain('2 more are left for the next run.')
        ->assertSuccessful();

    expect(Payment::query()->where('status', PaymentStatus::Pending)->pluck('id')->all())->toBe($payments->pluck('id')->slice(50)->values()->all());

    $this->artisan('nexus:billing:reconcile')
        ->expectsOutputToContain('Looked at 2 pending payments: 0 paid, 2 expired, 0 still pending.')
        ->doesntExpectOutputToContain('left for the next run')
        ->assertSuccessful();
});

it('starts on no more payments after 5 minutes, and leaves them for the next run', function (): void {
    $payMongo = FakePayMongo::fake();
    $slow = Payment::factory()->create(['created_at' => now()->subMinutes(30)]);
    $next = Payment::factory()->create(['created_at' => now()->subMinutes(20)]);
    $stale = Payment::factory()->create(['created_at' => now()->subDays(2)]);
    $payMongo->paid($slow)->paid($next)->open($stale)->beforeAnswering('read', function (): void {
        $this->travel(5)->minutes();
    });

    $this->artisan('nexus:billing:reconcile')
        ->expectsOutputToContain('Looked at 1 pending payment: 1 paid, 0 expired, 0 still pending.')
        ->expectsOutputToContain('2 more are left for the next run.')
        ->assertSuccessful();

    expect($slow->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($next->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($stale->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payMongo->requests())->toHaveCount(1);
});

it('looks only at pending payments', function (): void {
    $payMongo = FakePayMongo::fake();
    Payment::factory()->paid()->create(['created_at' => now()->subDays(2)]);
    Payment::factory()->expired()->create(['created_at' => now()->subDays(2)]);

    $this->artisan('nexus:billing:reconcile')
        ->expectsOutputToContain('No pending payments to reconcile.')
        ->assertSuccessful();

    expect($payMongo->requests())->toBe([]);
});

it('applies a payment once however often it runs', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->create(['created_at' => now()->subMinutes(5)]);
    $payMongo->paid($payment);

    $this->artisan('nexus:billing:reconcile')->assertSuccessful();
    $this->travel(10)->minutes();
    $this->artisan('nexus:billing:reconcile')
        ->expectsOutputToContain('No pending payments to reconcile.')
        ->assertSuccessful();

    expect($user->fresh()->pro_until)->toEqual(CarbonImmutable::parse('2027-10-03 06:00:00', 'UTC'))
        ->and($payMongo->requests())->toHaveCount(1);
});

it('applies a payment once when the webhook confirms it at the same time', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->create(['created_at' => now()->subMinutes(5)]);
    $payMongo->paid($payment);
    $delivered = false;
    $payMongo->beforeAnswering('read', function () use (&$delivered, $payment): void {
        if (! $delivered) {
            $delivered = true;
            $body = (string) json_encode(FakePayMongo::checkoutPaidEvent($payment));

            test()->call('POST', route('webhooks.paymongo'), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => FakePayMongo::signature($body)], content: $body)->assertOk();
        }
    });

    $this->artisan('nexus:billing:reconcile')->assertSuccessful();

    expect($delivered)->toBeTrue()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($user->fresh()->pro_until)->toEqual(CarbonImmutable::parse('2027-10-03 06:00:00', 'UTC'))
        ->and(DB::table('payments')->where('status', 'paid')->count())->toBe(1);
});

it('runs every 10 minutes, on one server, one run at a time', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();

    $events = collect(resolve(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'nexus:billing:reconcile'));

    expect($events)->toHaveCount(1)
        ->and($events->sole()->expression)->toBe('*/10 * * * *')
        ->and($events->sole()->onOneServer)->toBeTrue()
        ->and($events->sole()->withoutOverlapping)->toBeTrue()
        ->and($events->sole()->expiresAt)->toBe(10);
});
