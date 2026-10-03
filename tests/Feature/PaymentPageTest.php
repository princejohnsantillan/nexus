<?php

declare(strict_types=1);

use App\Actions\ConfirmPayment;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\FakePayMongo;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));
});

it('confirms the payment as soon as the user is back, and shows the receipt', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->create();
    $payMongo->paid($payment, 'gcash', 'ada@example.com', CarbonImmutable::parse('2026-10-03 05:59:30', 'UTC')->getTimestamp());

    $this->actingAs($user)
        ->get(route('billing.payments.show', $payment))
        ->assertOk()
        ->assertSeeTextInOrder([
            'You\'re on Pro',
            'Thanks for paying for Nexus. Create as many Stars and Connections as you need. PayMongo emailed your receipt to ada@example.com.',
            'Nexus Pro · Yearly', 'Paid with GCash · Oct 3, 2026', '₱4,999.00',
            'Pro until', 'Oct 3, 2027',
            'Create a Star', 'View billing',
        ])
        ->assertDontSee('data-plan-card', escape: false);

    $payment->refresh();

    expect($payment)
        ->status->toBe(PaymentStatus::Paid)
        ->method->toBe('gcash')
        ->card_brand->toBeNull()
        ->receipt_email->toBe('ada@example.com')
        ->and($payment->paid_at?->toIso8601String())->toBe('2026-10-03T05:59:30+00:00')
        ->and($payment->pro_from?->toIso8601String())->toBe('2026-10-03T06:00:00+00:00')
        ->and($payment->pro_until?->toIso8601String())->toBe('2027-10-03T06:00:00+00:00')
        ->and($user->fresh()->pro_until?->toIso8601String())->toBe('2027-10-03T06:00:00+00:00');
});

it('names the card a payment was made with', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->monthly()->create();
    $payMongo->paidByCard($payment, 'visa', '4345', email: null);

    Livewire::actingAs($user)->test('pages::billing.payments.show', ['payment' => $payment])
        ->assertSeeTextInOrder(['You\'re on Pro', 'PayMongo emailed you a receipt.', 'Nexus Pro · Monthly', 'Paid with Visa ···· 4345 · Oct 3, 2026', '₱499.00', 'Pro until', 'Nov 3, 2026']);

    expect($payment->fresh())
        ->method->toBe('card')
        ->card_brand->toBe('visa')
        ->card_last4->toBe('4345');
});

it('waits for PayMongo while the payment is pending, checking every 2 seconds until it is paid', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->create();
    $payMongo->open($payment);

    $page = Livewire::actingAs($user)->test('pages::billing.payments.show', ['payment' => $payment])
        ->assertSeeTextInOrder([
            'Confirming your payment',
            'We\'re waiting for PayMongo to confirm it. This usually takes a few seconds and the page updates on its own. You can leave: Pro starts the moment the payment is confirmed.',
            'Nexus Pro · Yearly', 'Waiting for PayMongo', '₱4,999.00', 'Status', 'Processing',
            'Back to billing',
        ])
        ->assertSeeHtml('wire:poll.2s="check"');

    $page->call('check')->assertSeeHtml('wire:poll.2s="check"');
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($user->fresh()->pro_until)->toBeNull();

    $payMongo->paid($payment, 'paymaya');
    $this->travel(2)->seconds();

    $page->call('check')->assertRedirect(route('billing.payments.show', $payment));
    Livewire::actingAs($user)->test('pages::billing.payments.show', ['payment' => $payment->fresh()])
        ->assertSeeText('You\'re on Pro')
        ->assertSeeText('Paid with Maya')
        ->assertDontSeeHtml('wire:poll');

    expect($user->fresh()->pro_until?->toIso8601String())->toBe('2027-10-03T06:00:02+00:00')
        ->and($payMongo->requests())->toHaveCount(3);
});

it('stops checking after two minutes, and checks again when asked', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->create();
    $payMongo->open($payment);

    $page = Livewire::actingAs($user)->test('pages::billing.payments.show', ['payment' => $payment]);

    $this->travel(2)->minutes();

    $page->call('check')
        ->assertSeeHtml('Still waiting for PayMongo')
        ->assertSeeHtml('PayMongo hasn&#039;t confirmed the payment yet. Pro starts as soon as it does, whether or not this page is open.')
        ->assertSeeHtml('Not confirmed yet')
        ->assertSeeHtml('Check again')
        ->assertDontSeeHtml('wire:poll');
    expect($payMongo->requests())->toHaveCount(1);

    $payMongo->paid($payment);

    $page->call('checkAgain')->assertRedirect(route('billing.payments.show', $payment));
    expect($payMongo->requests())->toHaveCount(2)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('keeps the payment pending when PayMongo can\'t be reached, and asks again on the next check', function (): void {
    $payMongo = FakePayMongo::fake()->respondTo('read', FakePayMongo::failure(503));
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->create();

    Livewire::actingAs($user)->test('pages::billing.payments.show', ['payment' => $payment])
        ->assertSeeText('Confirming your payment')
        ->call('check')
        ->assertSeeHtml('Confirming your payment');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payMongo->requests())->toHaveCount(2);
});

it('says an expired checkout charged nothing, and offers to try again for the same period', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->monthly()->create();
    $payMongo->expired($payment);

    $this->actingAs($user)
        ->get(route('billing.payments.show', $payment))
        ->assertOk()
        ->assertSeeTextInOrder(['This checkout expired', 'It closed before a payment went through, so nothing was charged.', 'Nexus Pro · Monthly', 'Not paid', '₱499.00', 'Status', 'Expired', 'Try again', 'Back to billing'])
        ->assertSee('href="'.route('billing.upgrade', ['period' => 'month']).'"', escape: false);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Expired)
        ->and($user->fresh()->pro_until)->toBeNull();
});

it('adds what is paid onto the Pro the user has, and says Pro was extended', function (string $period, string $proUntil, string $expected): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create(['pro_until' => CarbonImmutable::parse($proUntil, 'UTC')]);
    $payment = Payment::factory()->for($user)->state(['period' => $period, 'amount' => $period === 'month' ? 49900 : 499900])->create();
    $payMongo->paid($payment);

    Livewire::actingAs($user)->test('pages::billing.payments.show', ['payment' => $payment])
        ->assertSeeText('Pro extended')
        ->assertDontSeeText('You\'re on Pro');

    expect($payment->fresh()->pro_from?->toIso8601String())->toBe(CarbonImmutable::parse($proUntil, 'UTC')->toIso8601String())
        ->and($user->fresh()->pro_until?->toIso8601String())->toBe($expected);
})->with([
    'a month' => ['month', '2026-10-20 06:00:00', '2026-11-20T06:00:00+00:00'],
    'a year' => ['year', '2026-10-20 06:00:00', '2027-10-20T06:00:00+00:00'],
    'a month from the end of January' => ['month', '2027-01-31 06:00:00', '2027-02-28T06:00:00+00:00'],
    'a month from the end of January in a leap year' => ['month', '2028-01-31 06:00:00', '2028-02-29T06:00:00+00:00'],
    'a year from a leap day' => ['year', '2028-02-29 06:00:00', '2029-02-28T06:00:00+00:00'],
    'a month from May 1 in Manila, still April 30 in UTC' => ['month', '2027-04-30 18:00:00', '2027-05-31T18:00:00+00:00'],
    'a year from March 1 in Manila, still February 28 in UTC' => ['year', '2027-02-28 18:00:00', '2028-02-29T18:00:00+00:00'],
]);

it('starts Pro from now when the user\'s Pro has ended', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->proEnded()->create();
    $payment = Payment::factory()->for($user)->monthly()->create();
    $payMongo->paid($payment);

    Livewire::actingAs($user)->test('pages::billing.payments.show', ['payment' => $payment])
        ->assertSeeText('You\'re on Pro');

    expect($user->fresh()->pro_until?->toIso8601String())->toBe('2026-11-03T06:00:00+00:00');
});

it('stacks payments made one after another', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $monthly = Payment::factory()->for($user)->monthly()->create();
    $yearly = Payment::factory()->for($user)->create();
    $payMongo->paid($monthly)->paidByCard($yearly);

    Livewire::actingAs($user)->test('pages::billing.payments.show', ['payment' => $monthly])->assertSeeText('You\'re on Pro');
    Livewire::actingAs($user)->test('pages::billing.payments.show', ['payment' => $yearly])->assertSeeText('Pro extended');

    expect($yearly->fresh()->pro_from?->toIso8601String())->toBe('2026-11-03T06:00:00+00:00')
        ->and($user->fresh()->pro_until?->toIso8601String())->toBe('2027-11-03T06:00:00+00:00');
});

it('never extends Pro twice for one payment, however often the page is opened', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->create();
    $payMongo->paid($payment);

    $this->actingAs($user)->get(route('billing.payments.show', $payment))->assertSeeText('You\'re on Pro');
    $this->travel(1)->day();
    $this->actingAs($user)->get(route('billing.payments.show', $payment))->assertSeeText('You\'re on Pro');
    Livewire::actingAs($user)->test('pages::billing.payments.show', ['payment' => $payment->fresh()])->call('check')->call('checkAgain');

    expect($user->fresh()->pro_until?->toIso8601String())->toBe('2027-10-03T06:00:00+00:00')
        ->and($payMongo->requests())->toHaveCount(1);
});

it('applies a payment once when two confirmations race', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->create();
    $payMongo->paid($payment);

    // While this page waits for PayMongo, another confirmation (the webhook, or a second tab) gets there first.
    $raced = false;
    $payMongo->beforeAnswering('read', function () use (&$raced, $payment): void {
        if (! $raced) {
            $raced = true;
            resolve(ConfirmPayment::class)->handle($payment->fresh());
        }
    });

    Livewire::actingAs($user)->test('pages::billing.payments.show', ['payment' => $payment])->assertSeeText('You\'re on Pro');

    expect($user->fresh()->pro_until?->toIso8601String())->toBe('2027-10-03T06:00:00+00:00')
        ->and($payMongo->requests())->toHaveCount(2);
});

it('applies a checkout that was paid after Nexus took it for expired', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->create();
    $payMongo->open($payment);

    // PayMongo takes the payment while Nexus is expiring the checkout, so it refuses to expire it.
    $payMongo->beforeAnswering('expire', fn (): FakePayMongo => $payMongo->paid($payment));

    $this->actingAs($user)
        ->get(route('billing.upgrade', ['cancelled' => 1, 'payment' => $payment->reference]))
        ->assertRedirect(route('billing.payments.show', $payment));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($user->fresh()->pro_until?->toIso8601String())->toBe('2027-10-03T06:00:00+00:00');
});

it('is a 404 for another user\'s payment', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create();
    $payMongo->paid($payment);

    $this->actingAs(User::factory()->create())
        ->get(route('billing.payments.show', $payment))
        ->assertNotFound();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payMongo->requests())->toBe([]);
});

it('is a 404 for a reference no payment has', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/billing/payments/01k6nqz5k1m2n3p4q5r6s7t8v9')
        ->assertNotFound();
});

it('sends guests to sign in', function (): void {
    $payment = Payment::factory()->create();

    $this->get(route('billing.payments.show', $payment))->assertRedirect(route('auth.sign-in'));
});
