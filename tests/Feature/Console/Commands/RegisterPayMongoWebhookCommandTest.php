<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Support\FakePayMongo;

it('registers the webhook for paid checkouts and prints its secret once', function (): void {
    $payMongo = FakePayMongo::fake();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });

    Artisan::call('nexus:paymongo:webhook', ['url' => 'https://nexus.example.com/webhooks/paymongo']);
    $output = Artisan::output();

    $webhook = $payMongo->webhooks()[0];
    $secret = $webhook['attributes']['secret_key'];
    expect($payMongo->requests()[0]->data())->toBe(['data' => ['attributes' => [
        'url' => 'https://nexus.example.com/webhooks/paymongo',
        'events' => ['checkout_session.payment.paid'],
    ]]])
        ->and($output)->toContain("Registered a test-mode webhook {$webhook['id']} for checkout_session.payment.paid at https://nexus.example.com/webhooks/paymongo.")
        ->and($output)->toContain("\nPAYMONGO_WEBHOOK_SECRET={$secret}\n")
        ->and(substr_count($output, (string) $secret))->toBe(1)
        ->and($output)->toContain('Nexus shows this secret only now')
        ->and($logged)->toBe([]);
});

it('registers this Nexus\'s own webhook when given no URL', function (): void {
    $payMongo = FakePayMongo::fake();
    URL::forceRootUrl('https://nexus.example.com');
    URL::forceHttps();

    $this->artisan('nexus:paymongo:webhook')->assertSuccessful();

    expect($payMongo->webhooks()[0]['attributes']['url'])->toBe('https://nexus.example.com/webhooks/paymongo');
});

it('refuses a URL PayMongo can\'t send to, without asking PayMongo', function (string $url): void {
    $payMongo = FakePayMongo::fake();

    $this->artisan('nexus:paymongo:webhook', ['url' => $url])
        ->expectsOutputToContain('PayMongo sends events only to a public HTTPS URL')
        ->assertFailed();

    expect($payMongo->requests())->toBe([]);
})->with(['plain HTTP' => ['http://nexus.example.com/webhooks/paymongo'], 'not a URL' => ['nexus.example.com/webhooks/paymongo']]);

it('says so when PayMongo doesn\'t send the secret', function (): void {
    FakePayMongo::fake()->respondTo('createWebhook', fn (): PromiseInterface => Http::response(['data' => [
        'id' => 'hook_abc123',
        'type' => 'webhook',
        'attributes' => ['events' => ['checkout_session.payment.paid'], 'livemode' => false, 'status' => 'enabled', 'url' => 'https://nexus.example.com/webhooks/paymongo'],
    ]]));

    $this->artisan('nexus:paymongo:webhook', ['url' => 'https://nexus.example.com/webhooks/paymongo'])
        ->expectsOutputToContain('PayMongo didn\'t send the webhook\'s secret')
        ->doesntExpectOutputToContain('PAYMONGO_WEBHOOK_SECRET=')
        ->assertFailed();
});

it('lists the webhooks registered, without their secrets', function (): void {
    $payMongo = FakePayMongo::fake()
        ->withWebhook('https://nexus.example.com/webhooks/paymongo')
        ->withWebhook('https://old.example.com/hook', status: 'disabled', events: ['payment.paid', 'payment.failed']);
    [$current, $old] = $payMongo->webhooks();

    Artisan::call('nexus:paymongo:webhook', ['--list' => true]);
    $output = Artisan::output();

    expect($output)->toMatch("/{$current['id']}\\s+\\|\\s+https:\\/\\/nexus\\.example\\.com\\/webhooks\\/paymongo\\s+\\|\\s+checkout_session\\.payment\\.paid\\s+\\|\\s+enabled\\s+\\|\\s+test/")
        ->and($output)->toMatch("/{$old['id']}\\s+\\|\\s+https:\\/\\/old\\.example\\.com\\/hook\\s+\\|\\s+payment\\.paid, payment\\.failed\\s+\\|\\s+disabled\\s+\\|\\s+test/")
        ->and($output)->not->toContain('whsk_')
        ->and($payMongo->requests()[0]->url())->toBe('https://api.paymongo.com/v1/webhooks');
});

it('says when no webhooks are registered', function (): void {
    FakePayMongo::fake();

    $this->artisan('nexus:paymongo:webhook', ['--list' => true])
        ->expectsOutputToContain('PayMongo has no webhooks registered for this secret key.')
        ->assertSuccessful();
});

it('needs PayMongo set up', function (array $arguments): void {
    config(['services.paymongo.secret_key' => null]);
    Http::fake();

    $this->artisan('nexus:paymongo:webhook', $arguments)
        ->expectsOutputToContain('Payments aren\'t set up on this Nexus yet: set PAYMONGO_SECRET_KEY first.')
        ->assertFailed();

    Http::assertNothingSent();
})->with(['registering' => [['url' => 'https://nexus.example.com/webhooks/paymongo']], 'listing' => [['--list' => true]]]);
