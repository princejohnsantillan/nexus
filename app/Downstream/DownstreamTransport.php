<?php

declare(strict_types=1);

namespace App\Downstream;

use App\Concerns\KeepsSecretsInMemory;
use App\Exceptions\DownstreamRequestFailed;
use App\Exceptions\OutboundRequestBlocked;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Client\Exceptions\TransportException;
use Laravel\Mcp\Client\OAuth\WwwAuthenticateChallenge;
use Laravel\Mcp\Client\Transport\HttpTransport;
use Laravel\Mcp\Enums\ProtocolHandshake;
use Laravel\Mcp\Exceptions\SessionExpiredException;
use stdClass;
use Throwable;

/**
 * laravel/mcp's HTTP transport, adapted for the servers Nexus meets.
 *
 * - Requests go through Laravel's HTTP client, so the outbound guard checks
 *   and pins every one of them.
 * - The connect timeout limits connecting, the handshake and ending the
 *   session; the call timeout limits every other request.
 * - A failed request throws DownstreamRequestFailed with a Nexus-authored
 *   message. A server wants sign-in when it answers 401 or 403, or rejects
 *   the token with an `invalid_token` challenge under another status (GitHub
 *   answers 400 to a malformed token). Other 4xx rejections stay laravel/mcp's TransportException, with
 *   the status as its code, because they tell the protocol to fall back from
 *   `server/discover` to `initialize`.
 * - Errors that come back with a placeholder id (some SDKs answer a request
 *   they can't handle with `"id": "server-error"`) are readdressed to the
 *   request. Without that, the protocol can't pair the error with its request
 *   and never falls back to the older handshake.
 * - It keeps every raw message it receives. The protocol decodes JSON into
 *   arrays, which turns `{}` into `[]`, so the session reads results from the
 *   raw messages instead.
 *
 * It holds the Connection's credentials, so it can't be serialized.
 */
final class DownstreamTransport extends HttpTransport
{
    use KeepsSecretsInMemory;

    /**
     * Requests that belong to the handshake, which the connect timeout limits.
     *
     * @var list<string>
     */
    private const array HANDSHAKE_METHODS = ['server/discover', 'initialize', 'notifications/initialized'];

    /**
     * Raw messages received since the last result was taken.
     *
     * @var list<string>
     */
    private array $received = [];

    public function __construct(
        string $url,
        private readonly float $connectTimeout,
        private readonly float $callTimeout,
    ) {
        parent::__construct($url);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws DownstreamRequestFailed
     * @throws TransportException when the server rejects the request with a 4xx status
     */
    public function send(string $message, array $headers = []): void
    {
        $request = json_decode($message);
        $requestId = $request instanceof stdClass ? $request->id ?? null : null;
        $method = $request instanceof stdClass ? $request->method ?? null : null;
        $hadSession = $this->sessionId !== null;
        $alreadyQueued = count($this->queue);

        try {
            $response = Http::withHeaders($this->headers($headers))
                ->withBody($message, 'application/json')
                ->connectTimeout($this->connectTimeout)
                ->timeout(in_array($method, self::HANDSHAKE_METHODS, true) ? $this->connectTimeout : $this->callTimeout)
                ->post($this->url);
        } catch (OutboundRequestBlocked $blocked) {
            $this->reset();

            throw DownstreamRequestFailed::blocked($blocked);
        } catch (HttpClientException $exception) {
            $this->reset();

            throw $this->timedOut($exception) ? DownstreamRequestFailed::timedOut() : DownstreamRequestFailed::unreachable();
        }

        $this->captureSessionId($response);

        $status = $response->status();
        $challenge = WwwAuthenticateChallenge::parse($response->header('WWW-Authenticate'));

        if ($status === 401 || $status === 403 || ($response->failed() && $challenge->error === 'invalid_token')) {
            $this->reset();

            throw DownstreamRequestFailed::needsSignIn($status, $this->customHeaders !== [] || $this->token !== null, $challenge);
        }

        if ($status === 404 && $hadSession) {
            $this->reset();

            throw new SessionExpiredException('The server ended the session.');
        }

        if ($response->successful()) {
            if ($this->protocolVersion?->handshake() === ProtocolHandshake::Initialize) {
                $this->initialized = true;
            }

            if (str_contains($response->header('Content-Type'), 'text/event-stream')) {
                $this->readSseStream($response);
            } elseif (! $response->accepted() && trim($response->body()) !== '') {
                $this->queue[] = trim($response->body());
            }
        } elseif ($this->hasJsonRpcError(trim($response->body()))) {
            $this->queue[] = trim($response->body());
        } elseif ($status === 404 || ($response->serverError() && $status !== 501)) {
            $this->reset();

            throw DownstreamRequestFailed::httpError($status);
        } else {
            $this->reset();

            throw new TransportException("The server rejected the request with HTTP {$status}.", $status);
        }

        $this->readdressErrors($alreadyQueued, $requestId);
    }

    public function receive(): string
    {
        return $this->received[] = parent::receive();
    }

    /**
     * The result of the last response received, decoded with objects kept as
     * objects. Forgets every message received so far.
     */
    public function takeLastResult(): ?stdClass
    {
        [$received, $this->received] = [$this->received, []];

        foreach (array_reverse($received) as $message) {
            $response = json_decode($message);

            if ($response instanceof stdClass && ($response->result ?? null) instanceof stdClass) {
                return $response->result;
            }
        }

        return null;
    }

    /**
     * End an `initialize` session, as the server asked for one, within the connect timeout.
     */
    protected function terminateSession(): void
    {
        if ($this->sessionId === null) {
            return;
        }

        try {
            Http::withHeaders($this->headers())
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->connectTimeout)
                ->delete($this->url);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Give errors queued for this request the request's id, whatever id the server put on them.
     */
    private function readdressErrors(int $alreadyQueued, mixed $requestId): void
    {
        if (! is_int($requestId) && ! is_string($requestId)) {
            return;
        }

        foreach (array_slice($this->queue, $alreadyQueued, preserve_keys: true) as $index => $message) {
            $response = json_decode($message);

            if ($response instanceof stdClass && isset($response->error) && ($response->id ?? null) !== $requestId) {
                $response->id = $requestId;

                $this->queue[$index] = (string) json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            }
        }
    }

    /**
     * Whether the transfer timed out, rather than failing to connect or
     * breaking off. Guzzle throws one of its timeout exceptions, which the
     * HTTP client wraps.
     */
    private function timedOut(HttpClientException $exception): bool
    {
        for ($cause = $exception->getPrevious(); $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectTimeoutException || $cause instanceof NetworkTimeoutException || $cause instanceof ResponseTimeoutException) {
                return true;
            }
        }

        return false;
    }
}
