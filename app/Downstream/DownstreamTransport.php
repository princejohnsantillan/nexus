<?php

declare(strict_types=1);

namespace App\Downstream;

use App\Concerns\KeepsSecretsInMemory;
use App\Exceptions\DownstreamRequestFailed;
use App\Exceptions\OutboundRequestBlocked;
use Closure;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Date;
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
 *   session; the call timeout limits every other request. The session
 *   timeout is the most the session's requests take altogether, counted
 *   from its first one: each request gets the time left, if less, renewing
 *   an OAuth token included, and none is sent once it has run out. So a
 *   slow handshake can't push a tool call past the web request's time limit.
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
 * - Server-sent events are read as the SSE format defines them: an event's
 *   `data:` lines are joined, and comments and other fields are skipped.
 * - It keeps every raw message it receives, and can send the arguments of
 *   a tool call or a prompt as raw JSON. The protocol decodes and encodes
 *   JSON as PHP values, which turns `{}` into `[]` and rounds long numbers,
 *   so the session reads results from, and writes arguments into, the raw
 *   text.
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
     * Requests whose `arguments` sendingArguments() writes as raw JSON.
     *
     * @var list<string>
     */
    private const array METHODS_WITH_ARGUMENTS = ['tools/call', 'prompts/get'];

    /**
     * Raw messages received since the last result was taken.
     *
     * @var list<string>
     */
    private array $received = [];

    /**
     * The JSON object to send as the arguments of the tool call or prompt being requested.
     */
    private ?string $arguments = null;

    /**
     * The id of the last request sent, whose response takeResult() returns.
     */
    private int|string|null $lastRequestId = null;

    /**
     * When the session's time is up, in milliseconds since the epoch: the
     * session timeout after its first request started.
     */
    private ?int $deadline = null;

    public function __construct(
        string $url,
        private readonly float $connectTimeout,
        private readonly float $callTimeout,
        private readonly float $sessionTimeout,
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

        if (in_array($method, self::METHODS_WITH_ARGUMENTS, true) && $this->arguments !== null) {
            $message = $this->withArguments($message, $this->arguments);
        }

        if (is_string($method) && (is_int($requestId) || is_string($requestId))) {
            $this->lastRequestId = $requestId;
        }

        $this->deadline ??= Date::now()->getTimestampMs() + (int) round($this->sessionTimeout * 1000);

        try {
            $request = Http::withHeaders($this->headers($headers))->withBody($message, 'application/json');
            $timeout = $this->timeLeft(in_array($method, self::HANDSHAKE_METHODS, true) ? $this->connectTimeout : $this->callTimeout);

            if ($timeout <= 0.0) {
                $this->reset();

                throw DownstreamRequestFailed::timedOut();
            }

            $response = $request
                ->connectTimeout(min($this->connectTimeout, $timeout))
                ->timeout($timeout)
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
     * The result of the response to the last request sent, as the exact JSON
     * object the server sent; null when that response carries no object
     * result. Forgets every message received so far, including the
     * handshake's and notifications, which never count as the result.
     */
    public function takeResult(): ?string
    {
        [$received, $this->received] = [$this->received, []];

        foreach ($received as $message) {
            if ($this->lastRequestId === null || json_decode(RawJson::member($message, 'id') ?? 'null') !== $this->lastRequestId) {
                continue;
            }

            $result = RawJson::member($message, 'result');

            return $result !== null && RawJson::isObject($result) ? $result : null;
        }

        return null;
    }

    /**
     * Make a request whose `tools/call` or `prompts/get` messages carry these
     * arguments, the JSON object exactly as given, in place of the `{}` the
     * protocol encodes.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $request
     * @return TResult
     */
    public function sendingArguments(string $arguments, Closure $request): mixed
    {
        $this->arguments = $arguments;

        try {
            return $request();
        } finally {
            $this->arguments = null;
        }
    }

    /**
     * Read a server-sent event stream: each event's `data:` lines, joined by
     * newlines, are one message. Comments and the other fields are skipped,
     * and the last event needn't end with a blank line.
     */
    protected function readSseStream(ClientResponse $response): void
    {
        $data = [];

        foreach (preg_split('/\r\n|\r|\n/', $response->body()) ?: [] as $line) {
            if ($line === '') {
                $this->queueSseEvent(implode("\n", $data));
                $data = [];
            } elseif ($line === 'data' || str_starts_with($line, 'data:')) {
                $value = substr($line, 5);
                $data[] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
            }
        }

        $this->queueSseEvent(implode("\n", $data));
    }

    /**
     * End an `initialize` session, as the server asked for one, within the
     * connect timeout and the session's time left. With no time left, the
     * session is left for the server to expire.
     */
    protected function terminateSession(): void
    {
        if ($this->sessionId === null) {
            return;
        }

        try {
            $request = Http::withHeaders($this->headers());
            $timeout = $this->timeLeft($this->connectTimeout);

            if ($timeout > 0.0) {
                $request->connectTimeout($timeout)->timeout($timeout)->delete($this->url);
            }
        } catch (Throwable) {
            //
        }
    }

    /**
     * The seconds a request may take: its own limit, or the session's time
     * left when that is less (zero or below once it has run out).
     */
    private function timeLeft(float $limit): float
    {
        return $this->deadline === null ? $limit : min($limit, ($this->deadline - Date::now()->getTimestampMs()) / 1000);
    }

    /**
     * Give errors queued for this request the request's id, whatever id the
     * server put on them. The error itself is kept as the server sent it.
     */
    private function readdressErrors(int $alreadyQueued, mixed $requestId): void
    {
        if (! is_int($requestId) && ! is_string($requestId)) {
            return;
        }

        foreach (array_slice($this->queue, $alreadyQueued, preserve_keys: true) as $index => $message) {
            $error = RawJson::member($message, 'error');

            if ($error !== null && json_decode(RawJson::member($message, 'id') ?? 'null') !== $requestId) {
                $this->queue[$index] = '{"jsonrpc":"2.0","id":'.json_encode($requestId).',"error":'.$error.'}';
            }
        }
    }

    /**
     * Put the arguments into a `tools/call` or `prompts/get` message in place
     * of the empty object the protocol encoded. The first `"arguments":{}` in
     * the message is that one: only the tool's or prompt's name comes before
     * it, and a JSON string can't contain an unescaped quote.
     */
    private function withArguments(string $message, string $arguments): string
    {
        $placeholder = '"arguments":{}';
        $at = strpos($message, $placeholder);

        return $at === false ? $message : substr_replace($message, '"arguments":'.$arguments, $at, strlen($placeholder));
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
