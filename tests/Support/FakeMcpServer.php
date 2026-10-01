<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Downstream\RawJson;
use Closure;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use stdClass;

/**
 * A remote MCP server for tests, answering at one URL through Http::fake().
 *
 *     $server = FakeMcpServer::at('https://mcp.example.com/mcp')
 *         ->withTools([['name' => 'search', 'inputSchema' => ['type' => 'object']]])
 *         ->onCall('search', fn (stdClass $arguments): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
 *
 * Out of the box it is a 2025-11-25 server, like most today: it answers
 * `server/discover` with "method not found", then `initialize`, and lists no
 * tools. Script it further with:
 *
 * - withTools() and paginate(): what `tools/list` returns, page by page.
 *   Pass a JSON string to send each tool's text exactly as written (`{}`,
 *   long numbers and all); arrays are encoded, so `[]` stays `[]`.
 * - onCall(): what `tools/call` returns for a tool. Unknown tools get a
 *   JSON-RPC "invalid params" error; tools without a handler return "ok".
 * - speaking(): the protocol era. '2026-07-28' answers `server/discover`;
 *   2025-11-25, 2025-06-18 and 2025-03-26 answer `initialize` with that version.
 * - requireHeader() and challengingWith(): an auth challenge (401 or 403
 *   with a WWW-Authenticate header) for requests without the right header.
 * - streaming(): answer as server-sent events instead of plain JSON;
 *   streaming(splitData: true) spreads each message over several `data:`
 *   lines with CRLF line ends, a comment and an id, as the SSE format allows.
 * - respondTo(): replace the answer to one method with a failure, using
 *   error(), errorWithId(), httpStatus(), timeout(), unreachable() or raw().
 * - beforeAnswering(): run something while a request is in flight, such as
 *   deleting the Connection, to play out a race.
 *
 * Every request is recorded: received() returns the JSON-RPC messages,
 * decoded with objects kept as objects, requests() the HTTP requests and
 * transferOptions() the HTTP client's options for each, such as timeouts.
 * Tests call Http::preventStrayRequests(), so anything sent to another URL fails.
 */
final class FakeMcpServer
{
    public const string DEFAULT_URL = 'https://mcp.example.com/mcp';

    /**
     * Each tool's JSON, as it is sent.
     *
     * @var list<string>
     */
    private array $tools = [];

    private ?int $pageSize = null;

    private string $protocolVersion = '2025-11-25';

    /**
     * @var array<string, Closure(stdClass, stdClass): (array<array-key, mixed>|stdClass|string)>
     */
    private array $callHandlers = [];

    /**
     * @var array<string, Closure(stdClass, Request): PromiseInterface>
     */
    private array $responders = [];

    /**
     * @var array<string, Closure(stdClass): mixed>
     */
    private array $beforeAnswering = [];

    /**
     * @var array{name: string, value: string, status: int}|null
     */
    private ?array $requiredHeader = null;

    private string $challenge = 'Bearer resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource/mcp"';

    private bool $streams = false;

    private bool $splitsData = false;

    /**
     * @var list<Request>
     */
    private array $requests = [];

    /**
     * The HTTP client's transfer options for each request, such as its timeouts.
     *
     * @var list<array<string, mixed>>
     */
    private array $transferOptions = [];

    /**
     * @var list<stdClass>
     */
    private array $received = [];

    private function __construct(public readonly string $url) {}

    /**
     * Start answering requests to the URL.
     */
    public static function at(string $url = self::DEFAULT_URL): self
    {
        $server = new self($url);

        Http::fake([$url => $server->answer(...)]);

        return $server;
    }

    /**
     * @param  list<array<string, mixed>|stdClass>|string  $tools  Tool definitions, or their JSON array.
     */
    public function withTools(array|string $tools): self
    {
        $this->tools = is_string($tools)
            ? RawJson::elements($tools) ?? throw new InvalidArgumentException('The tools must be a JSON array.')
            : array_map(fn (array|stdClass $tool): string => (string) json_encode($tool), $tools);

        return $this;
    }

    /**
     * List tools this many to a page, with cursors.
     */
    public function paginate(int $pageSize): self
    {
        $this->pageSize = $pageSize;

        return $this;
    }

    /**
     * @param  string  $protocolVersion  '2026-07-28', '2025-11-25', '2025-06-18' or '2025-03-26'.
     */
    public function speaking(string $protocolVersion): self
    {
        $this->protocolVersion = $protocolVersion;

        return $this;
    }

    /**
     * Answer `tools/call` for a tool with what the handler returns: the result's JSON, or a value to encode.
     *
     * @param  Closure(stdClass $arguments, stdClass $message): (array<array-key, mixed>|stdClass|string)  $handler
     */
    public function onCall(string $tool, Closure $handler): self
    {
        $this->callHandlers[$tool] = $handler;

        return $this;
    }

    /**
     * Answer one JSON-RPC method with the responder instead.
     *
     * @param  Closure(stdClass $message, Request $request): PromiseInterface  $responder
     */
    public function respondTo(string $method, Closure $responder): self
    {
        $this->responders[$method] = $responder;

        return $this;
    }

    /**
     * Run the callback when a request for the method arrives, before answering it.
     *
     * @param  Closure(stdClass $message): mixed  $callback
     */
    public function beforeAnswering(string $method, Closure $callback): self
    {
        $this->beforeAnswering[$method] = $callback;

        return $this;
    }

    /**
     * Refuse requests that don't carry this header and value.
     */
    public function requireHeader(string $name, string $value, int $status = 401): self
    {
        $this->requiredHeader = ['name' => $name, 'value' => $value, 'status' => $status];

        return $this;
    }

    /**
     * The WWW-Authenticate header sent with a refusal.
     */
    public function challengingWith(string $wwwAuthenticate): self
    {
        $this->challenge = $wwwAuthenticate;

        return $this;
    }

    /**
     * Answer as server-sent events.
     */
    public function streaming(bool $splitData = false): self
    {
        $this->streams = true;
        $this->splitsData = $splitData;

        return $this;
    }

    /**
     * The JSON-RPC messages received, optionally only those for one method.
     *
     * @return list<stdClass>
     */
    public function received(?string $method = null): array
    {
        return array_values(array_filter(
            $this->received,
            fn (stdClass $message): bool => $method === null || ($message->method ?? null) === $method,
        ));
    }

    /**
     * Every HTTP request received.
     *
     * @return list<Request>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * The HTTP client's transfer options for every request received, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function transferOptions(): array
    {
        return $this->transferOptions;
    }

    /**
     * A JSON-RPC error for the request.
     *
     * @return Closure(stdClass, Request): PromiseInterface
     */
    public static function error(int $code, string $message = 'Something went wrong.', int $status = 200): Closure
    {
        return fn (stdClass $request): PromiseInterface => self::json(['jsonrpc' => '2.0', 'id' => $request->id ?? null, 'error' => ['code' => $code, 'message' => $message]], $status);
    }

    /**
     * A JSON-RPC error carrying another id than the request's, such as the
     * placeholder "server-error" some SDKs send.
     *
     * @return Closure(stdClass, Request): PromiseInterface
     */
    public static function errorWithId(string|int|null $id, int $code, string $message = 'Something went wrong.', int $status = 200): Closure
    {
        return fn (): PromiseInterface => self::json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $status);
    }

    /**
     * An HTTP status with a body that isn't JSON-RPC.
     *
     * @param  array<string, string>  $headers
     * @return Closure(stdClass, Request): PromiseInterface
     */
    public static function httpStatus(int $status, string $body = 'Something went wrong.', array $headers = []): Closure
    {
        return fn (): PromiseInterface => Http::response($body, $status, ['Content-Type' => 'text/plain', ...$headers]);
    }

    /**
     * A body sent as is, e.g. malformed JSON.
     *
     * @return Closure(stdClass, Request): PromiseInterface
     */
    public static function raw(string $body, string $contentType = 'application/json', int $status = 200): Closure
    {
        return fn (): PromiseInterface => Http::response($body, $status, ['Content-Type' => $contentType]);
    }

    /**
     * Curl giving up waiting for an answer.
     *
     * @return Closure(stdClass, Request): PromiseInterface
     */
    public static function timeout(): Closure
    {
        return fn (stdClass $message, Request $request): PromiseInterface => Create::rejectionFor(new NetworkTimeoutException(
            'cURL error 28: Operation timed out after 55001 milliseconds with 0 bytes received',
            $request->toPsrRequest(),
        ));
    }

    /**
     * Curl failing to connect at all.
     *
     * @return Closure(stdClass, Request): PromiseInterface
     */
    public static function unreachable(): Closure
    {
        return fn (stdClass $message, Request $request): PromiseInterface => Http::failedConnection(
            'cURL error 7: Failed to connect to mcp.example.com port 443: Connection refused',
        )($request);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function answer(Request $request, array $options): PromiseInterface
    {
        $this->requests[] = $request;
        $this->transferOptions[] = $options;

        if ($request->method() === 'DELETE') {
            return Http::response(status: 204);
        }

        if ($this->requiredHeader !== null && $request->header($this->requiredHeader['name']) !== [$this->requiredHeader['value']]) {
            return Http::response('', $this->requiredHeader['status'], ['WWW-Authenticate' => $this->challenge]);
        }

        $message = json_decode($request->body());

        if (! $message instanceof stdClass) {
            return self::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
        }

        $this->received[] = $message;
        $method = is_string($message->method ?? null) ? $message->method : '';

        if (isset($this->beforeAnswering[$method])) {
            ($this->beforeAnswering[$method])($message);
        }

        if (isset($this->responders[$method])) {
            return ($this->responders[$method])($message, $request);
        }

        if (! isset($message->id)) {
            return Http::response(status: 202);
        }

        $modern = $this->protocolVersion === '2026-07-28';

        return match ($method) {
            'server/discover' => $modern
                ? $this->result($message, [
                    'supportedVersions' => ['2026-07-28'],
                    'capabilities' => ['tools' => new stdClass],
                    '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'fake', 'version' => '1.0.0']],
                ])
                : self::error(-32601, 'Method not found')($message, $request),
            'initialize' => $this->result($message, [
                'protocolVersion' => $this->protocolVersion,
                'capabilities' => ['tools' => new stdClass],
                'serverInfo' => ['name' => 'fake', 'version' => '1.0.0'],
            ], ['Mcp-Session-Id' => 'fake-session']),
            'tools/list' => $this->listTools($message),
            'tools/call' => $this->callTool($message, $request),
            default => self::error(-32601, 'Method not found')($message, $request),
        };
    }

    private function listTools(stdClass $message): PromiseInterface
    {
        $offset = (int) ($message->params->cursor ?? 0);
        $tools = $this->pageSize === null ? $this->tools : array_slice($this->tools, $offset, $this->pageSize);
        $next = $this->pageSize !== null && $offset + $this->pageSize < count($this->tools) ? (string) ($offset + $this->pageSize) : null;

        return $this->result($message, '{"tools":['.implode(',', $tools).']'.($next === null ? '' : ',"nextCursor":'.json_encode($next)).'}');
    }

    private function callTool(stdClass $message, Request $request): PromiseInterface
    {
        $name = $message->params->name ?? null;
        $arguments = ($message->params->arguments ?? null) instanceof stdClass ? $message->params->arguments : new stdClass;

        if (! is_string($name) || ! $this->hasTool($name)) {
            return self::error(-32602, 'Unknown tool: '.(is_string($name) ? $name : ''))($message, $request);
        }

        $handler = $this->callHandlers[$name] ?? fn (): array => ['content' => [['type' => 'text', 'text' => 'ok']], 'isError' => false];

        return $this->result($message, $handler($arguments, $message));
    }

    private function hasTool(string $name): bool
    {
        foreach ($this->tools as $tool) {
            if ((json_decode($tool)->name ?? null) === $name) {
                return true;
            }
        }

        return isset($this->callHandlers[$name]);
    }

    /**
     * @param  array<array-key, mixed>|stdClass|string  $result  The result, or its JSON.
     * @param  array<string, string>  $headers
     */
    private function result(stdClass $message, array|stdClass|string $result, array $headers = []): PromiseInterface
    {
        $body = '{"jsonrpc":"2.0","id":'.json_encode($message->id ?? null).',"result":'.(is_string($result) ? $result : json_encode($result)).'}';

        if ($this->splitsData) {
            [$first, $rest] = [substr($body, 0, 17), substr($body, 17)];

            return Http::response(": keep-alive\r\nevent: message\r\nid: 1\r\ndata: {$first}\r\ndata:{$rest}\r\n\r\n", 200, ['Content-Type' => 'text/event-stream', ...$headers]);
        }

        if ($this->streams) {
            return Http::response("event: message\ndata: {$body}\n\n", 200, ['Content-Type' => 'text/event-stream', ...$headers]);
        }

        return Http::response($body, 200, ['Content-Type' => 'application/json', ...$headers]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function json(array $payload, int $status): PromiseInterface
    {
        return Http::response((string) json_encode($payload), $status, ['Content-Type' => 'application/json']);
    }
}
