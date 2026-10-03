<?php

declare(strict_types=1);

namespace App\Billing;

use App\Exceptions\OutboundRequestBlocked;
use App\Exceptions\PayMongoRequestFailed;
use Closure;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayMongo's API, for checkout sessions on its hosted checkout: creating
 * one, reading it back and expiring it.
 *
 * Requests go through Laravel's HTTP client, so the outbound guard checks
 * them, and sign in with the secret key from `services.paymongo` (HTTP
 * Basic, the key as the username). Without a key, payments aren't set up
 * and nothing is sent. Every failure throws PayMongoRequestFailed with
 * Nexus's own message; what PayMongo answered is never shown, logged or
 * stored, and only the HTTP status of a refusal is logged.
 */
final readonly class PayMongo
{
    public const string API_URL = 'https://api.paymongo.com';

    /**
     * Seconds to wait for PayMongo to accept the connection.
     */
    private const float CONNECT_TIMEOUT = 5;

    /**
     * Seconds to wait for PayMongo's answer, well inside a web request's limit.
     */
    private const float TIMEOUT = 15;

    /**
     * Whether this Nexus takes payments: it has a PayMongo secret key.
     */
    public static function isSetUp(): bool
    {
        $secretKey = config('services.paymongo.secret_key');

        return is_string($secretKey) && $secretKey !== '';
    }

    /**
     * The payment methods checkout offers, by PayMongo's names.
     *
     * @return list<string>
     */
    public static function paymentMethods(): array
    {
        return array_values(array_filter(
            config()->array('services.paymongo.payment_methods'),
            is_string(...),
        ));
    }

    /**
     * Create a checkout session. A request repeated with the same
     * idempotency key gets the same session.
     *
     * @param  array<string, mixed>  $attributes  The session's attributes, as PayMongo's API names them.
     *
     * @throws PayMongoRequestFailed
     */
    public function createCheckoutSession(array $attributes, string $idempotencyKey): CheckoutSession
    {
        return $this->session(
            'create checkout session',
            fn (PendingRequest $request): Response => $request
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/v2/checkout_sessions', ['data' => ['attributes' => $attributes]]),
            PayMongoRequestFailed::checkoutNotStarted(...),
        );
    }

    /**
     * Read a checkout session, with the payments made on it.
     *
     * @throws PayMongoRequestFailed
     */
    public function checkoutSession(string $id): CheckoutSession
    {
        return $this->session(
            'read checkout session',
            fn (PendingRequest $request): Response => $request->get('/v1/checkout_sessions/'.$this->sessionPath($id)),
            PayMongoRequestFailed::checkoutNotRead(...),
        );
    }

    /**
     * Expire a checkout session, so it can't be paid any more. PayMongo
     * refuses to expire one that was paid or has expired already.
     *
     * @throws PayMongoRequestFailed
     */
    public function expireCheckoutSession(string $id): CheckoutSession
    {
        return $this->session(
            'expire checkout session',
            fn (PendingRequest $request): Response => $request->post('/v1/checkout_sessions/'.$this->sessionPath($id).'/expire'),
            PayMongoRequestFailed::checkoutNotRead(...),
        );
    }

    /**
     * Send a request about a checkout session, and read the session it answers with.
     *
     * @param  Closure(PendingRequest): Response  $send
     * @param  Closure(): PayMongoRequestFailed  $failure
     *
     * @throws PayMongoRequestFailed
     */
    private function session(string $name, Closure $send, Closure $failure): CheckoutSession
    {
        $secretKey = config('services.paymongo.secret_key');

        if (! is_string($secretKey) || $secretKey === '') {
            throw PayMongoRequestFailed::notSetUp();
        }

        try {
            $response = $send(Http::baseUrl(self::API_URL)
                ->withBasicAuth($secretKey, '')
                ->acceptJson()
                ->asJson()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT));
        } catch (OutboundRequestBlocked|HttpClientException|MalformedUriException $exception) {
            Log::warning('A PayMongo request failed.', ['request' => $name, 'reason' => $exception::class]);

            throw $failure();
        }

        $data = $response->successful() ? $this->data($response) : null;
        $session = $data !== null ? CheckoutSession::fromArray($data) : null;

        if (! $session instanceof CheckoutSession) {
            Log::warning('A PayMongo request failed.', ['request' => $name, 'status' => $response->status()]);

            throw $failure();
        }

        return $session;
    }

    /**
     * The `data` object of a JSON response, or null when it has none.
     *
     * @return array<mixed>|null
     */
    private function data(Response $response): ?array
    {
        $body = json_decode($response->body(), true);
        $data = is_array($body) ? ($body['data'] ?? null) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * A session id as a path segment. Ids come from PayMongo's own answers,
     * so one that isn't shaped like PayMongo's is never sent.
     *
     * @throws PayMongoRequestFailed
     */
    private function sessionPath(string $id): string
    {
        if (preg_match('/\Acs_[A-Za-z0-9]+\z/', $id) !== 1) {
            throw PayMongoRequestFailed::checkoutNotRead();
        }

        return $id;
    }
}
