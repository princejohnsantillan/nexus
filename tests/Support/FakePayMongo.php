<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Billing\PayMongo;
use App\Models\Payment;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * PayMongo's API for tests, answering through Http::fake() at
 * https://api.paymongo.com, so the real client and the outbound guard run.
 * It also gives Nexus a test secret key, which it checks on every request,
 * and a webhook secret (WEBHOOK_SECRET).
 *
 *     $payMongo = FakePayMongo::fake();
 *     // … "Continue to payment" creates a session …
 *     $payMongo->paid($payment, 'gcash');                 // the user paid on PayMongo
 *     $payMongo->paidByCard($payment, 'visa', '4345');
 *     $payMongo->expired($payment);                       // it expired unpaid
 *
 * It keeps checkout sessions the way PayMongo does: creating one
 * (`POST /v2/checkout_sessions`, the same session again for a repeated
 * `Idempotency-Key`) answers with only its id and URL; reading one
 * (`GET /v1/checkout_sessions/{id}`) answers with everything, including its
 * payments, and leaves a paid session's own status `active`, as PayMongo
 * does; expiring one (`POST …/expire`) refuses a paid or expired session
 * with a 400. An unknown session is a 404.
 *
 * Sessions are named by their id or by the Payment holding it, so a
 * factory-made payment's session can be scripted without creating it:
 * open() makes it known, and paid(), paidByCard() and expired() make it
 * known in that state. respondTo('create' | 'read' | 'expire', $responder)
 * replaces an answer, such as with failure(); beforeAnswering() runs a
 * callback while a request is in flight, to play out a race.
 *
 * It keeps webhooks too: registering one (`POST /v1/webhooks`) answers with
 * the webhook and its new secret, and listing them (`GET /v1/webhooks`)
 * answers with every one, secrets included, as PayMongo does. withWebhook()
 * makes one known beforehand; respondTo('createWebhook' | 'listWebhooks')
 * replaces an answer, and respondNormallyTo() undoes any respondTo(). For PayMongo's deliveries to Nexus's webhook,
 * checkoutPaidEvent() and event() build an event's body, in either shape
 * PayMongo documents, and signature() signs one as PayMongo does.
 *
 * Afterwards, requests() returns what it received, created() the attributes
 * of each session created, lastSessionId() the newest session's id and
 * webhooks() the webhooks it knows.
 */
final class FakePayMongo
{
    public const string SECRET_KEY = 'sk_test_fakepaymongo';

    /**
     * The secret Nexus's webhook deliveries are signed with.
     */
    public const string WEBHOOK_SECRET = 'whsk_fakepaymongo';

    /**
     * The request names respondTo() and beforeAnswering() take.
     *
     * @var list<string>
     */
    private const array REQUESTS = ['create', 'read', 'expire', 'createWebhook', 'listWebhooks'];

    /**
     * The sessions it knows, by id.
     *
     * @var array<string, array{status: string, attributes: array<string, mixed>, payments: list<array<string, mixed>>}>
     */
    private array $sessions = [];

    /**
     * The webhooks it knows, by id, as PayMongo answers with them.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $webhooks = [];

    /**
     * Session ids by the idempotency key they were created with.
     *
     * @var array<string, string>
     */
    private array $idempotencyKeys = [];

    /**
     * @var array<string, Closure(Request): PromiseInterface>
     */
    private array $responders = [];

    /**
     * @var array<string, Closure(Request): void>
     */
    private array $beforeAnswering = [];

    /**
     * @var list<Request>
     */
    private array $requests = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $created = [];

    private function __construct() {}

    /**
     * Give Nexus a test secret key and webhook secret, and start answering PayMongo's API.
     */
    public static function fake(): self
    {
        config(['services.paymongo.secret_key' => self::SECRET_KEY, 'services.paymongo.webhook_secret' => self::WEBHOOK_SECRET]);

        $payMongo = new self;

        Http::fake([PayMongo::API_URL.'/*' => $payMongo->answer(...)]);

        return $payMongo;
    }

    /**
     * Know the session, open and unpaid.
     */
    public function open(Payment|string $session, int $amount = 499900): self
    {
        $id = $this->idOf($session);

        $this->sessions[$id] ??= [
            'status' => 'active',
            'attributes' => [
                'line_items' => [['name' => 'Nexus Pro · Yearly', 'amount' => $session instanceof Payment ? $session->amount : $amount, 'currency' => 'PHP', 'quantity' => 1]],
                'reference_number' => $session instanceof Payment ? $session->reference : null,
            ],
            'payments' => [],
        ];

        return $this;
    }

    /**
     * Have the session paid with an e-wallet or QR Ph, by PayMongo's name for
     * it (`gcash`, `paymaya`, `qrph`, `grab_pay`), at this Unix time (now
     * unless given), with the receipt sent to this address.
     */
    public function paid(Payment|string $session, string $method = 'gcash', ?string $email = 'buyer@example.com', ?int $paidAt = null): self
    {
        return $this->addPayment($session, ['id' => 'src_'.Str::random(24), 'type' => $method], $email, $paidAt);
    }

    /**
     * Have the session paid by card, which PayMongo names by brand and last 4.
     */
    public function paidByCard(Payment|string $session, string $brand = 'visa', string $last4 = '4345', ?string $email = 'buyer@example.com', ?int $paidAt = null): self
    {
        return $this->addPayment($session, ['brand' => $brand, 'country' => 'US', 'id' => 'card_'.Str::random(24), 'last4' => $last4, 'type' => 'card'], $email, $paidAt);
    }

    /**
     * Have the session expired, unpaid unless it was paid before.
     */
    public function expired(Payment|string $session): self
    {
        $this->open($session);
        $this->sessions[$this->idOf($session)]['status'] = 'expired';

        return $this;
    }

    /**
     * Know a webhook registered before, at the URL.
     *
     * @param  list<string>  $events
     */
    public function withWebhook(string $url, string $status = 'enabled', array $events = ['checkout_session.payment.paid'], bool $livemode = false): self
    {
        $this->addWebhook($url, $events, $status, $livemode);

        return $this;
    }

    /**
     * The webhooks it knows, as PayMongo answers with them.
     *
     * @return list<array<string, mixed>>
     */
    public function webhooks(): array
    {
        return array_values($this->webhooks);
    }

    /**
     * The body of a `checkout_session.payment.paid` delivery for the
     * payment's checkout session: the session with a paid payment on it,
     * whatever PayMongo would answer about it, in PayMongo's event envelope
     * or, with $hostedCheckoutShape, as its hosted checkout guide shows it.
     *
     * @return array<string, mixed>
     */
    public static function checkoutPaidEvent(Payment $payment, bool $livemode = false, bool $hostedCheckoutShape = false): array
    {
        return self::event('checkout_session.payment.paid', [
            'id' => $payment->checkout_session_id,
            'type' => 'checkout_session',
            'attributes' => [
                'checkout_url' => $payment->checkout_url,
                'livemode' => $livemode,
                'reference_number' => $payment->reference,
                'status' => 'active',
                'payments' => [[
                    'id' => 'pay_'.Str::random(24),
                    'type' => 'payment',
                    'attributes' => ['amount' => $payment->amount, 'currency' => 'PHP', 'livemode' => $livemode, 'status' => 'paid', 'source' => ['type' => 'gcash']],
                ]],
            ],
        ], $livemode, $hostedCheckoutShape);
    }

    /**
     * The body of a delivery of an event of the type, about the resource:
     * PayMongo's event envelope (the event in `data.attributes`, the
     * resource in `data.attributes.data`) or, with $hostedCheckoutShape,
     * the shape its hosted checkout guide shows (the event in `data`, the
     * resource in `data.data`).
     *
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    public static function event(string $type, array $resource, bool $livemode = false, bool $hostedCheckoutShape = false): array
    {
        if ($hostedCheckoutShape) {
            return [
                'event_type' => 'send.webhook',
                'data' => [
                    'type' => $type,
                    'resource' => $resource['type'] ?? null,
                    'livemode' => $livemode,
                    'organization_id' => 'org_'.Str::random(24),
                    'created_at' => now()->toIso8601ZuluString(),
                    'updated_at' => now()->toIso8601ZuluString(),
                    'data' => $resource,
                ],
            ];
        }

        return [
            'data' => [
                'id' => 'evt_'.Str::random(24),
                'type' => 'event',
                'attributes' => [
                    'type' => $type,
                    'livemode' => $livemode,
                    'data' => $resource,
                    'previous_data' => [],
                    'created_at' => now()->getTimestamp(),
                    'updated_at' => now()->getTimestamp(),
                ],
            ],
        ];
    }

    /**
     * The `Paymongo-Signature` header PayMongo sends with the body: the
     * HMAC-SHA256 of "{t}.{body}" with the secret, as `te` for a test-mode
     * event or `li` for a live one, the other left empty.
     */
    public static function signature(string $body, bool $livemode = false, string $secret = self::WEBHOOK_SECRET, ?int $timestamp = null): string
    {
        $timestamp ??= now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $livemode ? "t={$timestamp},te=,li={$signature}" : "t={$timestamp},te={$signature},li=";
    }

    /**
     * Answer one kind of request (`create`, `read`, `expire`, `createWebhook` or `listWebhooks`) with the responder instead.
     *
     * @param  Closure(Request): PromiseInterface  $responder
     */
    public function respondTo(string $request, Closure $responder): self
    {
        $this->responders[$this->requestName($request)] = $responder;

        return $this;
    }

    /**
     * Answer a kind of request as PayMongo would again, after respondTo().
     */
    public function respondNormallyTo(string $request): self
    {
        unset($this->responders[$this->requestName($request)]);

        return $this;
    }

    /**
     * Run the callback while a kind of request (`create`, `read`, `expire`,
     * `createWebhook` or `listWebhooks`) is in flight, before it is answered.
     *
     * @param  Closure(Request): void  $callback
     */
    public function beforeAnswering(string $request, Closure $callback): self
    {
        $this->beforeAnswering[$this->requestName($request)] = $callback;

        return $this;
    }

    /**
     * A failure as PayMongo sends one: an error object carrying the text, with the status.
     *
     * @return Closure(Request): PromiseInterface
     */
    public static function failure(int $status = 500, string $detail = 'Something went wrong.'): Closure
    {
        return fn (): PromiseInterface => Http::response(['errors' => [['code' => 'server_error', 'detail' => $detail]]], $status);
    }

    /**
     * A connection that never reaches PayMongo.
     *
     * @return Closure(Request): PromiseInterface
     */
    public static function unreachable(): Closure
    {
        return fn (Request $request): PromiseInterface => Http::failedConnection()($request);
    }

    /**
     * @return list<Request>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * The attributes of each session created, as Nexus sent them.
     *
     * @return list<array<string, mixed>>
     */
    public function created(): array
    {
        return $this->created;
    }

    public function lastSessionId(): ?string
    {
        return array_key_last($this->sessions);
    }

    /**
     * The status the session's own object reports: `active` or `expired`.
     */
    public function statusOf(Payment|string $session): ?string
    {
        return $this->sessions[$this->idOf($session)]['status'] ?? null;
    }

    private function answer(Request $request): PromiseInterface
    {
        $this->requests[] = $request;

        if ($request->header('Authorization') !== ['Basic '.base64_encode(self::SECRET_KEY.':')]) {
            return Http::response(['errors' => [['code' => 'unauthorized', 'detail' => 'Invalid API key.']]], 401);
        }

        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $name = match (true) {
            $request->method() === 'POST' && $path === '/v2/checkout_sessions' => 'create',
            $request->method() === 'GET' && preg_match('#\A/v1/checkout_sessions/[^/]+\z#', $path) === 1 => 'read',
            $request->method() === 'POST' && preg_match('#\A/v1/checkout_sessions/[^/]+/expire\z#', $path) === 1 => 'expire',
            $request->method() === 'POST' && $path === '/v1/webhooks' => 'createWebhook',
            $request->method() === 'GET' && $path === '/v1/webhooks' => 'listWebhooks',
            default => null,
        };

        if ($name === null) {
            return Http::response(['errors' => [['code' => 'resource_not_found', 'detail' => 'Not found.']]], 404);
        }

        if (isset($this->beforeAnswering[$name])) {
            ($this->beforeAnswering[$name])($request);
        }

        if (isset($this->responders[$name])) {
            return ($this->responders[$name])($request);
        }

        $id = explode('/', $path)[3] ?? '';

        return match ($name) {
            'create' => $this->create($request),
            'read' => $this->read($id),
            'expire' => $this->expire($id),
            'createWebhook' => $this->createWebhook($request),
            'listWebhooks' => Http::response(['has_more' => false, 'total_records' => count($this->webhooks), 'data' => array_values($this->webhooks)]),
        };
    }

    private function create(Request $request): PromiseInterface
    {
        $attributes = $request->data()['data']['attributes'] ?? null;

        if (! is_array($attributes)) {
            return Http::response(['errors' => [['code' => 'parameter_required', 'detail' => 'data.attributes is required.']]], 400);
        }

        $key = $request->header('Idempotency-Key')[0] ?? null;
        $id = $key !== null ? ($this->idempotencyKeys[$key] ?? null) : null;

        if ($id === null) {
            $id = 'cs_'.Str::lower(Str::random(24));
            $this->sessions[$id] = ['status' => 'active', 'attributes' => $attributes, 'payments' => []];
            $this->created[] = $attributes;

            if ($key !== null) {
                $this->idempotencyKeys[$key] = $id;
            }
        }

        return Http::response(['data' => [
            'id' => $id,
            'type' => 'checkout_session',
            'attributes' => ['checkout_url' => $this->checkoutUrl($id), 'livemode' => false, 'created_at' => now()->getTimestamp(), 'updated_at' => now()->getTimestamp()],
        ]]);
    }

    private function read(string $id): PromiseInterface
    {
        if (! isset($this->sessions[$id])) {
            return Http::response(['errors' => [['code' => 'not_found', 'detail' => 'Checkout session not found']]], 404);
        }

        return Http::response(['data' => $this->sessionObject($id)]);
    }

    private function expire(string $id): PromiseInterface
    {
        if (! isset($this->sessions[$id])) {
            return Http::response(['errors' => [['code' => 'not_found', 'detail' => 'Checkout session not found']]], 404);
        }

        if ($this->hasPaidPayment($id)) {
            return Http::response(['errors' => [['code' => 'invalid_request_body', 'detail' => 'Checkout session is already paid']]], 400);
        }

        if ($this->sessions[$id]['status'] === 'expired') {
            return Http::response(['errors' => [['code' => 'invalid_request_body', 'detail' => 'Checkout session is already expired']]], 400);
        }

        $this->sessions[$id]['status'] = 'expired';

        return Http::response(['data' => $this->sessionObject($id)]);
    }

    private function createWebhook(Request $request): PromiseInterface
    {
        $attributes = $request->data()['data']['attributes'] ?? null;
        $url = is_array($attributes) ? $attributes['url'] ?? null : null;
        $events = is_array($attributes) ? $attributes['events'] ?? null : null;

        if (! is_string($url) || ! is_array($events) || $events === []) {
            return Http::response(['errors' => [['code' => 'parameter_required', 'detail' => 'url and events are required.']]], 400);
        }

        return Http::response(['data' => $this->addWebhook($url, array_values(array_filter($events, is_string(...))), 'enabled', false)]);
    }

    /**
     * @param  list<string>  $events
     * @return array<string, mixed>
     */
    private function addWebhook(string $url, array $events, string $status, bool $livemode): array
    {
        $id = 'hook_'.Str::random(24);

        return $this->webhooks[$id] = [
            'id' => $id,
            'type' => 'webhook',
            'attributes' => [
                'events' => $events,
                'livemode' => $livemode,
                'secret_key' => 'whsk_'.Str::random(24),
                'status' => $status,
                'url' => $url,
                'created_at' => now()->getTimestamp(),
                'updated_at' => now()->getTimestamp(),
            ],
        ];
    }

    /**
     * The full session object, as reading it answers.
     *
     * @return array<string, mixed>
     */
    private function sessionObject(string $id): array
    {
        $session = $this->sessions[$id];

        return [
            'id' => $id,
            'type' => 'checkout_session',
            'attributes' => [
                ...$session['attributes'],
                'checkout_url' => $this->checkoutUrl($id),
                'livemode' => false,
                'payment_intent' => null,
                'payments' => $session['payments'],
                'status' => $session['status'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function addPayment(Payment|string $session, array $source, ?string $email, ?int $paidAt): self
    {
        $this->open($session);
        $id = $this->idOf($session);
        $amount = $this->sessions[$id]['attributes']['line_items'][0]['amount'] ?? 499900;
        $paidAt ??= now()->getTimestamp();

        $this->sessions[$id]['payments'][] = [
            'id' => 'pay_'.Str::random(24),
            'type' => 'payment',
            'attributes' => [
                'amount' => $amount,
                'billing' => ['address' => [], 'email' => $email, 'name' => 'Test Buyer', 'phone' => null],
                'currency' => 'PHP',
                'livemode' => false,
                'paid_at' => $paidAt,
                'source' => $source,
                'status' => 'paid',
                'created_at' => $paidAt,
                'updated_at' => $paidAt,
            ],
        ];

        return $this;
    }

    private function hasPaidPayment(string $id): bool
    {
        foreach ($this->sessions[$id]['payments'] as $payment) {
            if (($payment['attributes']['status'] ?? null) === 'paid') {
                return true;
            }
        }

        return false;
    }

    private function idOf(Payment|string $session): string
    {
        $id = $session instanceof Payment ? $session->checkout_session_id : $session;

        return $id ?? throw new InvalidArgumentException('The payment has no checkout session.');
    }

    private function requestName(string $request): string
    {
        return in_array($request, self::REQUESTS, true) ? $request : throw new InvalidArgumentException("PayMongo has no {$request} request here.");
    }

    private function checkoutUrl(string $id): string
    {
        return 'https://checkout.paymongo.com/'.Str::after($id, 'cs_');
    }
}
