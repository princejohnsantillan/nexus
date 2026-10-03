<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\DownstreamCanary;
use Tests\Support\FakePayMongo;

/*
 * PayMongo failing, with text Nexus doesn't know (DownstreamCanary::TEXT)
 * in its answer or the transfer's error, never puts that text in front of
 * the user, in the log or in the database: starting a checkout, reading it
 * back on the return page, and expiring a cancelled one.
 */

beforeEach(function (): void {
    $this->canary = DownstreamCanary::watch();
    $this->payMongo = FakePayMongo::fake();
    $this->user = User::factory()->create();
});

dataset('PayMongo failures', fn (): array => [
    'an error answer' => [FakePayMongo::failure(500, DownstreamCanary::TEXT)],
    'a refusal' => [FakePayMongo::failure(400, DownstreamCanary::TEXT)],
    'a success that isn\'t a session' => [fn (): PromiseInterface => Http::response(['data' => ['id' => DownstreamCanary::TEXT]])],
    'a broken connection' => [DownstreamCanary::brokenConnection(...)],
    'a broken transfer' => [DownstreamCanary::brokenTransfer(...)],
]);

it('says only that the checkout couldn\'t start', function (Closure $failure): void {
    $this->payMongo->respondTo('create', $failure);

    Livewire::actingAs($this->user)->test('pages::billing.upgrade')
        ->call('continueToPayment')
        ->assertHasErrors(['checkout' => 'PayMongo couldn\'t start the checkout. Try again in a minute.'])
        ->assertDontSeeHtml(DownstreamCanary::PREFIX);

    expect($this->canary->sightings())->toBe([])
        ->and(Payment::query()->count())->toBe(0);
})->with('PayMongo failures');

it('keeps waiting on the return page', function (Closure $failure): void {
    $payment = Payment::factory()->for($this->user)->create();
    $this->payMongo->respondTo('read', $failure);

    $this->actingAs($this->user)
        ->get(route('billing.payments.show', $payment))
        ->assertOk()
        ->assertSeeText('Confirming your payment')
        ->assertDontSee(DownstreamCanary::PREFIX);

    expect($this->canary->sightings())->toBe([])
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);
})->with('PayMongo failures');

it('still says a cancelled checkout charged nothing', function (Closure $failure): void {
    $payment = Payment::factory()->for($this->user)->create();
    $reads = 0;
    $this->payMongo
        ->respondTo('expire', $failure)
        ->respondTo('read', function (Request $request) use (&$reads, $payment, $failure): PromiseInterface {
            // The first read finds the checkout open, so Nexus expires it; the read after the refused expiry fails too.
            return ++$reads === 1
                ? Http::response(['data' => ['id' => $payment->checkout_session_id, 'attributes' => ['checkout_url' => $payment->checkout_url, 'status' => 'active', 'payments' => []]]])
                : $failure($request);
        });

    $this->actingAs($this->user)
        ->get(route('billing.upgrade', ['cancelled' => 1, 'payment' => $payment->reference]))
        ->assertOk()
        ->assertSeeText('Payment cancelled. Nothing was charged.')
        ->assertDontSee(DownstreamCanary::PREFIX);

    expect($this->payMongo->requests())->toHaveCount(3)
        ->and($reads)->toBe(2)
        ->and($this->canary->sightings())->toBe([])
        ->and(json_encode(DB::table('payments')->get()))->not->toContain(DownstreamCanary::PREFIX);
})->with('PayMongo failures');
