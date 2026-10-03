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
 * It also gives Nexus a test secret key, which it checks on every request.
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
 * Afterwards, requests() returns what it received, created() the attributes
 * of each session created, and lastSessionId() the newest session's id.
 */
final class FakePayMongo
{
    public const string SECRET_KEY = 'sk_test_fakepaymongo';

    /**
     * The sessions it knows, by id.
     *
     * @var array<string, array{status: string, attributes: array<string, mixed>, payments: list<array<string, mixed>>}>
     */
    private array $sessions = [];

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
     * Give Nexus a test secret key and start answering PayMongo's API.
     */
    public static function fake(): self
    {
        config(['services.paymongo.secret_key' => self::SECRET_KEY]);

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
     * Answer one kind of request (`create`, `read` or `expire`) with the responder instead.
     *
     * @param  Closure(Request): PromiseInterface  $responder
     */
    public function respondTo(string $request, Closure $responder): self
    {
        $this->responders[$this->requestName($request)] = $responder;

        return $this;
    }

    /**
     * Run the callback while a kind of request (`create`, `read` or `expire`)
     * is in flight, before it is answered.
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
        return in_array($request, ['create', 'read', 'expire'], true) ? $request : throw new InvalidArgumentException("PayMongo has no {$request} request here.");
    }

    private function checkoutUrl(string $id): string
    {
        return 'https://checkout.paymongo.com/'.Str::after($id, 'cs_');
    }
}
