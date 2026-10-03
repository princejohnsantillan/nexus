<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Jobs\ConfirmPaymentInBackground;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\DownstreamCanary;
use Tests\Support\FakePayMongo;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));
});

/**
 * Deliver the event to the webhook as PayMongo does: the JSON body, signed
 * in the event's mode unless another `Paymongo-Signature` is given (null
 * sends none).
 *
 * @param  array<string, mixed>  $event
 */
function deliverToWebhook(array $event, string|false|null $signature = false): TestResponse
{
    $body = (string) json_encode($event);
    $livemode = (bool) ($event['data']['attributes']['livemode'] ?? $event['data']['livemode'] ?? false);
    $signature = $signature === false ? FakePayMongo::signature($body, $livemode) : $signature;

    return test()->call('POST', route('webhooks.paymongo'), server: array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_PAYMONGO_SIGNATURE' => $signature,
    ], fn (?string $value): bool => $value !== null), content: $body);
}

it('puts the user on Pro once, for a signed paid checkout, however often it is delivered', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->monthly()->create();
    $payMongo->paidByCard($payment, 'visa', '4345');
    $event = FakePayMongo::checkoutPaidEvent($payment);

    deliverToWebhook($event)->assertOk()->assertExactJson(['received' => true]);

    expect($payment->fresh())
        ->status->toBe(PaymentStatus::Paid)
        ->method->toBe('card')
        ->card_last4->toBe('4345')
        ->pro_until->toEqual(CarbonImmutable::parse('2026-11-03 06:00:00', 'UTC'));
    expect($user->fresh()->pro_until)->toEqual(CarbonImmutable::parse('2026-11-03 06:00:00', 'UTC'));

    $this->travel(5)->minutes();
    deliverToWebhook($event)->assertOk()->assertExactJson(['received' => true]);

    expect($user->fresh()->pro_until)->toEqual(CarbonImmutable::parse('2026-11-03 06:00:00', 'UTC'))
        ->and($payMongo->requests())->toHaveCount(1);
});

it('reads the shape PayMongo\'s hosted checkout guide shows', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create();
    $payMongo->paid($payment, 'gcash');

    deliverToWebhook(FakePayMongo::checkoutPaidEvent($payment, hostedCheckoutShape: true))->assertOk()->assertExactJson(['received' => true]);

    expect($payment->fresh())
        ->status->toBe(PaymentStatus::Paid)
        ->method->toBe('gcash');
});

it('finds the payment by its reference number when the session id is unknown', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create();
    $payMongo->paid($payment);
    $event = FakePayMongo::checkoutPaidEvent($payment);
    $event['data']['attributes']['data']['id'] = 'cs_unknownsession';

    deliverToWebhook($event)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payMongo->requests()[0]->url())->toBe("https://api.paymongo.com/v1/checkout_sessions/{$payment->checkout_session_id}");
});

it('checks a live-mode event against its live signature', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create();
    $payMongo->paid($payment);

    deliverToWebhook(FakePayMongo::checkoutPaidEvent($payment, livemode: true))->assertOk()->assertExactJson(['received' => true]);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('refuses a delivery PayMongo didn\'t sign, and changes nothing', function (Closure $signatureFor): void {
    Queue::fake([ConfirmPaymentInBackground::class]);
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create();
    $payMongo->paid($payment);
    $event = FakePayMongo::checkoutPaidEvent($payment);

    deliverToWebhook($event, $signatureFor((string) json_encode($event)))
        ->assertForbidden()
        ->assertExactJson(['received' => false]);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payMongo->requests())->toBe([]);
    Queue::assertNotPushed(ConfirmPaymentInBackground::class);
})->with([
    'no signature' => [fn (string $body): ?string => null],
    'an empty signature' => [fn (string $body): string => ''],
    'a signature without a time' => [fn (string $body): string => 'te='.hash_hmac('sha256', ".{$body}", FakePayMongo::WEBHOOK_SECRET).',li='],
    'another secret\'s signature' => [fn (string $body): string => FakePayMongo::signature($body, secret: 'whsk_someoneelse')],
    'a signature of another body' => [fn (string $body): string => FakePayMongo::signature($body.' ')],
    'a signature with another time' => [fn (string $body): string => str_replace('t='.now()->getTimestamp(), 't='.(now()->getTimestamp() + 1), FakePayMongo::signature($body))],
    'a live-mode signature on a test-mode event' => [fn (string $body): string => FakePayMongo::signature($body, livemode: true)],
]);

it('refuses a live-mode event signed only for test mode', function (): void {
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create();
    $payMongo->paid($payment);
    $event = FakePayMongo::checkoutPaidEvent($payment, livemode: true);

    deliverToWebhook($event, FakePayMongo::signature((string) json_encode($event), livemode: false))->assertForbidden();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payMongo->requests())->toBe([]);
});

it('refuses every delivery while no webhook secret is set', function (?string $secret): void {
    $payMongo = FakePayMongo::fake();
    config(['services.paymongo.webhook_secret' => $secret]);
    $payment = Payment::factory()->create();
    $payMongo->paid($payment);
    $event = FakePayMongo::checkoutPaidEvent($payment);

    deliverToWebhook($event, FakePayMongo::signature((string) json_encode($event), secret: ''))->assertForbidden();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payMongo->requests())->toBe([]);
})->with(['unset' => [null], 'empty' => ['']]);

it('refuses a body that isn\'t an event, even when signed', function (string $body): void {
    $payMongo = FakePayMongo::fake();

    $this->call('POST', route('webhooks.paymongo'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PAYMONGO_SIGNATURE' => FakePayMongo::signature($body),
    ], content: $body)->assertForbidden();

    expect($payMongo->requests())->toBe([]);
})->with([
    'not JSON' => ['checkout_session.payment.paid'],
    'no data' => ['{"type":"checkout_session.payment.paid"}'],
    'no mode' => ['{"data":{"attributes":{"type":"checkout_session.payment.paid","data":{"id":"cs_abc"}}}}'],
]);

it('never takes the event\'s word that the checkout was paid', function (): void {
    $payMongo = FakePayMongo::fake();
    $user = User::factory()->create();
    $payment = Payment::factory()->for($user)->create();
    $payMongo->open($payment);

    deliverToWebhook(FakePayMongo::checkoutPaidEvent($payment))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($user->fresh()->pro_until)->toBeNull()
        ->and($payMongo->requests())->toHaveCount(1);
});

it('answers at once and leaves the confirming to the queue', function (): void {
    Queue::fake([ConfirmPaymentInBackground::class]);
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create();
    $payMongo->paid($payment);

    deliverToWebhook(FakePayMongo::checkoutPaidEvent($payment))->assertOk()->assertExactJson(['received' => true]);

    Queue::assertPushed(ConfirmPaymentInBackground::class, fn (ConfirmPaymentInBackground $job): bool => $job->paymentId === $payment->id);
    expect($payMongo->requests())->toBe([])
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('takes and ignores what it has nothing to do with', function (Closure $eventFor): void {
    Queue::fake([ConfirmPaymentInBackground::class]);
    $payMongo = FakePayMongo::fake();

    deliverToWebhook($eventFor())->assertOk()->assertExactJson(['received' => true]);

    Queue::assertNotPushed(ConfirmPaymentInBackground::class);
    expect($payMongo->requests())->toBe([]);
})->with([
    'another type of event' => [fn (): array => FakePayMongo::event('payment.paid', ['id' => 'pay_abc', 'type' => 'payment', 'attributes' => ['status' => 'paid']])],
    'another type of event about a pending checkout' => [fn (): array => FakePayMongo::event('payment.failed', FakePayMongo::checkoutPaidEvent(Payment::factory()->create())['data']['attributes']['data'])],
    'a checkout Nexus doesn\'t know' => [fn (): array => FakePayMongo::checkoutPaidEvent(Payment::factory()->make(['reference' => '01k6n0000000000000000000zz']))],
    'a checkout with no id or reference' => [fn (): array => FakePayMongo::event('checkout_session.payment.paid', ['type' => 'checkout_session', 'attributes' => []])],
    'a checkout that expired' => [fn (): array => FakePayMongo::checkoutPaidEvent(Payment::factory()->expired()->create())],
    'a checkout already paid' => [fn (): array => FakePayMongo::checkoutPaidEvent(Payment::factory()->paid()->create())],
]);

it('keeps a delivery\'s body out of the log', function (): void {
    $canary = DownstreamCanary::watch();
    $payMongo = FakePayMongo::fake();
    $payment = Payment::factory()->create();
    $payMongo->respondTo('read', FakePayMongo::failure(500, DownstreamCanary::TEXT));
    $event = FakePayMongo::checkoutPaidEvent($payment);
    $event['data']['attributes']['data']['attributes']['description'] = DownstreamCanary::TEXT;

    deliverToWebhook($event)->assertOk();
    deliverToWebhook($event, 'not a signature')->assertForbidden();
    deliverToWebhook(FakePayMongo::event(DownstreamCanary::TEXT, ['id' => DownstreamCanary::TEXT]))->assertOk();

    expect($canary->sightings())->toBe([])
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('limits deliveries from one address', function (): void {
    config(['nexus.limits.paymongo_webhooks_per_minute' => 2]);
    FakePayMongo::fake();
    $event = FakePayMongo::event('payment.paid', ['id' => 'pay_abc']);

    deliverToWebhook($event)->assertOk();
    deliverToWebhook($event)->assertOk();
    deliverToWebhook($event)->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    deliverToWebhook($event)->assertOk();
});

it('starts no session', function (): void {
    FakePayMongo::fake();

    deliverToWebhook(FakePayMongo::event('payment.paid', ['id' => 'pay_abc']))
        ->assertOk()
        ->assertHeaderMissing('Set-Cookie');
});
