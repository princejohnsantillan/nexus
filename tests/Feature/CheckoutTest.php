<?php

declare(strict_types=1);

use App\Enums\BillingPeriod;
use App\Enums\PaymentStatus;
use App\Jobs\ExpireStaleCheckout;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakePayMongo;

beforeEach(function (): void {
    config([
        'nexus.plans.pro.prices' => ['month' => 49900, 'year' => 499900],
        'services.paymongo.payment_methods' => ['card', 'gcash', 'paymaya', 'qrph'],
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));
});

it('starts a checkout on PayMongo for the period picked, and sends the browser there', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    Livewire::actingAs($user)->test('pages::billing.upgrade')
        ->assertSeeText('Continue to payment')
        ->assertDontSeeText('Payments aren\'t set up on this Nexus yet.')
        ->set('period', 'month')
        ->call('continueToPayment')
        ->assertHasNoErrors()
        ->assertRedirect('https://checkout.paymongo.com/'.Str::after($payMongo->lastSessionId(), 'cs_'));

    $payment = Payment::query()->sole();

    expect($payment->user->is($user))->toBeTrue()
        ->and($payment->period)->toBe(BillingPeriod::Month)
        ->and($payment->amount)->toBe(49900)
        ->and($payment->currency)->toBe('PHP')
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->checkout_session_id)->toBe($payMongo->lastSessionId())
        ->and($payment->reference)->toMatch('/\A[0-9a-z]{26}\z/');

    expect($payMongo->created())->toBe([[
        'line_items' => [['name' => 'Nexus Pro · Monthly', 'amount' => 49900, 'currency' => 'PHP', 'quantity' => 1]],
        'payment_method_types' => ['card', 'gcash', 'paymaya', 'qrph'],
        'success_url' => route('billing.payments.show', $payment),
        'cancel_url' => route('billing.upgrade', ['period' => 'month', 'cancelled' => 1, 'payment' => $payment->reference]),
        'reference_number' => $payment->reference,
        'description' => 'A month of Nexus Pro. It doesn\'t renew on its own.',
        'send_email_receipt' => true,
        'billing' => ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
    ]]);

    $create = $payMongo->requests()[0];
    expect($create->method())->toBe('POST')
        ->and($create->url())->toBe('https://api.paymongo.com/v2/checkout_sessions')
        ->and($create->header('Idempotency-Key'))->toBe([$payment->reference]);
});

it('charges a year at the yearly price, and names only what it knows of the user', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create(['name' => 'octocat', 'email' => null]);

    Livewire::actingAs($user)->test('pages::billing.upgrade')->call('continueToPayment');

    expect($payMongo->created()[0])
        ->line_items->toBe([['name' => 'Nexus Pro · Yearly', 'amount' => 499900, 'currency' => 'PHP', 'quantity' => 1]])
        ->description->toBe('A year of Nexus Pro. It doesn\'t renew on its own.')
        ->billing->toBe(['name' => 'octocat']);
});

it('prices the checkout from the plan, whatever the page sends', function (): void {
    $payMongo = FakePayMongo::fake();

    Livewire::actingAs(User::factory()->create())->test('pages::billing.upgrade')
        ->set('period', 'decade')
        ->call('continueToPayment');

    expect(Payment::query()->sole())
        ->period->toBe(BillingPeriod::Year)
        ->amount->toBe(499900);
    expect($payMongo->created()[0]['line_items'][0]['amount'])->toBe(499900);
});

it('sends the browser to the new checkout without waiting on PayMongo for the user\'s older ones', function (): void {
    Queue::fake([ExpireStaleCheckout::class]);
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $older = Payment::factory()->for($user)->count(2)->create();
    Payment::factory()->for($user)->paid()->create();
    Payment::factory()->for($user)->expired()->create();
    Payment::factory()->create();

    Livewire::actingAs($user)->test('pages::billing.upgrade')
        ->call('continueToPayment')
        ->assertRedirect('https://checkout.paymongo.com/'.Str::after($payMongo->lastSessionId(), 'cs_'));

    expect($payMongo->requests())->toHaveCount(1);
    Queue::assertPushedTimes(ExpireStaleCheckout::class, 2);
    foreach ($older as $payment) {
        Queue::assertPushed(ExpireStaleCheckout::class, fn (ExpireStaleCheckout $job): bool => $job->paymentId === $payment->id);
    }
});

it('expires the user\'s older pending checkouts on the queue when they start another', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $older = Payment::factory()->for($user)->create();
    $paidMeanwhile = Payment::factory()->for($user)->monthly()->create();
    $someoneElses = Payment::factory()->create();
    $payMongo->open($older)->paid($paidMeanwhile)->open($someoneElses);

    Livewire::actingAs($user)->test('pages::billing.upgrade')->call('continueToPayment');

    expect($older->fresh()->status)->toBe(PaymentStatus::Expired)
        ->and($payMongo->statusOf($older))->toBe('expired')
        ->and($paidMeanwhile->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($someoneElses->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payMongo->statusOf($someoneElses))->toBe('active')
        ->and($user->payments()->where('status', PaymentStatus::Pending)->count())->toBe(1);
});

it('says PayMongo couldn\'t start the checkout, and keeps nothing of it', function (): void {
    $payMongo = FakePayMongo::fake()->respondTo('create', FakePayMongo::failure(500, 'Internal details: merchant 42 on db-7'));

    Livewire::actingAs(User::factory()->create())->test('pages::billing.upgrade')
        ->call('continueToPayment')
        ->assertNoRedirect()
        ->assertHasErrors(['checkout' => 'PayMongo couldn\'t start the checkout. Try again in a minute.'])
        ->assertDontSeeHtml('merchant 42');

    expect(Payment::query()->count())->toBe(0)
        ->and($payMongo->requests())->toHaveCount(1);
});

it('shows the not-set-up callout and never calls PayMongo without a secret key', function (): void {
    Http::fake();
    config(['services.paymongo.secret_key' => null]);

    Livewire::actingAs(User::factory()->create())->test('pages::billing.upgrade')
        ->assertSeeText('Payments aren\'t set up on this Nexus yet.')
        ->assertDontSeeText('Continue to payment')
        ->call('continueToPayment')
        ->assertNoRedirect()
        ->assertHasErrors(['checkout' => 'Payments aren\'t set up on this Nexus yet.']);

    Http::assertNothingSent();
    expect(Payment::query()->count())->toBe(0);
});

it('says a cancelled checkout charged nothing, expires it and changes nothing else', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->monthly()->create();
    $payMongo->open($payment);

    $this->actingAs($user)
        ->get(route('billing.upgrade', ['period' => 'month', 'cancelled' => 1, 'payment' => $payment->reference]))
        ->assertOk()
        ->assertSeeText('Payment cancelled. Nothing was charged.');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Expired)
        ->and($payMongo->statusOf($payment))->toBe('expired')
        ->and($user->fresh()->pro_until)->toBeNull();

    Livewire::actingAs($user)->withQueryParams(['period' => 'month', 'cancelled' => '1'])->test('pages::billing.upgrade')
        ->assertSet('period', 'month')
        ->assertSeeText('Payment cancelled. Nothing was charged.');
});

it('sends a checkout that was paid after all to its receipt instead of calling it cancelled', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->create();
    $payMongo->paid($payment);

    $this->actingAs($user)
        ->get(route('billing.upgrade', ['period' => 'year', 'cancelled' => 1, 'payment' => $payment->reference]))
        ->assertRedirect(route('billing.payments.show', $payment));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($user->fresh()->pro_until?->toIso8601String())->toBe('2027-10-03T06:00:00+00:00');
});

it('leaves another user\'s checkout alone when a cancel link names it', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create();
    $payMongo->open($payment);

    $this->actingAs(User::factory()->create())
        ->get(route('billing.upgrade', ['cancelled' => 1, 'payment' => $payment->reference]))
        ->assertOk()
        ->assertSeeText('Payment cancelled. Nothing was charged.');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payMongo->requests())->toBe([]);
});

it('still says nothing was charged when PayMongo can\'t be reached to expire the checkout', function (): void {
    $payMongo = FakePayMongo::fake()->respondTo('read', FakePayMongo::unreachable());
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->create();
    $payMongo->open($payment);

    $this->actingAs($user)
        ->get(route('billing.upgrade', ['cancelled' => 1, 'payment' => $payment->reference]))
        ->assertOk()
        ->assertSeeText('Payment cancelled. Nothing was charged.');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});
