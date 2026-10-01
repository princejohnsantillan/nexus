<?php

namespace Tests\Support;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use stdClass;

/**
 * A downstream MCP server for Http::fake().
 *
 * Responses are written as raw JSON so tests can prove that `{}` survives
 * the trip through Nexus. Every request body is recorded, decoded with
 * objects kept as objects.
 */
class FakeMcpServer
{
    /** @var list<stdClass> */
    public array $received = [];

    /** @var list<array<string, list<string>>> */
    public array $headers = [];

    /** The raw JSON of the tools/list result's "tools" array. */
    public string $toolsJson = '[]';

    /** Answer server/discover (2026-07-28) instead of only initialize. */
    public bool $modern = false;

    /** The protocol version a legacy server answers initialize with. */
    public string $initializeVersion = '2025-11-25';

    /**
     * When set, requests must carry this Authorization header (or one of
     * these, when given a list).
     *
     * @var string|list<string>|null
     */
    public string|array|null $requireAuthorization = null;

    /** The scope the 401 challenge asks for, if any. */
    public ?string $challengeScope = 'read write';

    /** Where the 401 challenge says the protected resource metadata lives. */
    public string $resourceMetadataUrl = 'https://svc.example.com/.well-known/oauth-protected-resource/mcp';

    /** @var (Closure(stdClass): string)|null Returns the raw JSON of a tools/call result. */
    public ?Closure $onCall = null;

    public function __construct(public string $url = 'https://svc.example.com/mcp') {}

    public function fake(): static
    {
        Http::fake([$this->url => $this->handler()]);

        return $this;
    }

    /**
     * @return Closure(Request): PromiseInterface
     */
    public function handler(): Closure
    {
        return fn (Request $request): PromiseInterface => $this($request);
    }

    public function __invoke(Request $request): PromiseInterface
    {
        $this->headers[] = $request->headers();

        if ($this->requireAuthorization !== null && ! in_array($request->header('Authorization')[0] ?? null, (array) $this->requireAuthorization, true)) {
            return Http::response('', 401, [
                'WWW-Authenticate' => "Bearer resource_metadata=\"{$this->resourceMetadataUrl}\"".($this->challengeScope === null ? '' : ", scope=\"{$this->challengeScope}\""),
            ]);
        }

        $message = json_decode($request->body(), false);
        $this->received[] = $message;

        if (! isset($message->id)) {
            return Http::response('', 202);
        }

        return match ($message->method) {
            'server/discover' => $this->modern
                ? $this->result($message, '{"supportedVersions":["2026-07-28"],"capabilities":{"tools":{}},"_meta":{"io.modelcontextprotocol/serverInfo":{"name":"fake","version":"1.0.0"}}}')
                : $this->error($message, -32601, 'Method not found'),
            'initialize' => $this->result($message, json_encode([
                'protocolVersion' => $this->initializeVersion,
                'capabilities' => ['tools' => new stdClass],
                'serverInfo' => ['name' => 'fake', 'version' => '1.0.0'],
            ])),
            'tools/list' => $this->result($message, '{"tools":'.$this->toolsJson.'}'),
            'tools/call' => $this->result($message, $this->onCall instanceof Closure
                ? ($this->onCall)($message)
                : '{"content":[{"type":"text","text":"ok"}],"isError":false}'),
            default => $this->error($message, -32601, 'Method not found'),
        };
    }

    /**
     * @return list<stdClass>
     */
    public function receivedCalls(): array
    {
        return array_values(array_filter($this->received, fn (stdClass $message): bool => ($message->method ?? null) === 'tools/call'));
    }

    protected function result(stdClass $message, string $resultJson): PromiseInterface
    {
        return Http::response('{"jsonrpc":"2.0","id":'.json_encode($message->id).',"result":'.$resultJson.'}', 200, ['Content-Type' => 'application/json']);
    }

    protected function error(stdClass $message, int $code, string $text): PromiseInterface
    {
        return Http::response(json_encode(['jsonrpc' => '2.0', 'id' => $message->id, 'error' => ['code' => $code, 'message' => $text]]), 200, ['Content-Type' => 'application/json']);
    }
}
