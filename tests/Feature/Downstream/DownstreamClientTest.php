<?php

declare(strict_types=1);

use App\Downstream\DownstreamClient;
use App\Downstream\DownstreamSession;
use App\Downstream\RawJson;
use App\Enums\DownstreamFailure;
use App\Exceptions\DownstreamRequestFailed;
use App\Models\Connection;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Tests\Support\FakeMcpServer;

/**
 * Open a session to the Connection and run the request, returning the failure it throws.
 *
 * @param  Closure(DownstreamSession): mixed  $request
 */
function downstreamFailure(Connection $connection, Closure $request): DownstreamRequestFailed
{
    try {
        $request(resolve(DownstreamClient::class)->session($connection));
    } catch (DownstreamRequestFailed $failed) {
        return $failed;
    }

    throw new RuntimeException('The request did not fail.');
}

/**
 * The arguments of the first tool call the server received, as the exact JSON sent.
 */
function sentArguments(FakeMcpServer $server): ?string
{
    $call = collect($server->requests())->first(fn (Request $request): bool => (json_decode($request->body())->method ?? null) === 'tools/call');

    return RawJson::member(RawJson::member($call?->body() ?? '', 'params') ?? '', 'arguments');
}

it('lists tools exactly as a 2025-11-25 server sends them, empty objects included', function (): void {
    $server = FakeMcpServer::at()->withTools('[{"name":"search","inputSchema":{"type":"object","properties":{}},"annotations":{}}]');

    $tools = resolve(DownstreamClient::class)->session(Connection::factory()->create())->listTools();

    expect($tools)->toBe(['{"name":"search","inputSchema":{"type":"object","properties":{}},"annotations":{}}'])
        ->and(array_map(fn (stdClass $message): ?string => $message->method ?? null, $server->received()))
        ->toBe(['server/discover', 'initialize', 'notifications/initialized', 'tools/list'])
        ->and($server->received('initialize')[0]->params->protocolVersion)->toBe('2025-11-25');
});

it('speaks 2026-07-28 through server/discover when the server supports it', function (): void {
    $server = FakeMcpServer::at()->speaking('2026-07-28')->withTools([['name' => 'search']]);

    $tools = resolve(DownstreamClient::class)->session(Connection::factory()->create())->listTools();

    expect($tools)->toHaveCount(1)
        ->and($server->received('initialize'))->toBeEmpty()
        ->and($server->requests()[1]->header('MCP-Protocol-Version'))->toBe(['2026-07-28'])
        ->and($server->received('tools/list')[0]->params->_meta->{'io.modelcontextprotocol/protocolVersion'})->toBe('2026-07-28');
});

it('falls back to initialize for a server on an older protocol version', function (string $version): void {
    $server = FakeMcpServer::at()->speaking($version)->withTools([['name' => 'search']]);

    $tools = resolve(DownstreamClient::class)->session(Connection::factory()->create())->listTools();

    expect($tools)->toHaveCount(1)
        ->and($server->requests()[3]->header('MCP-Protocol-Version'))->toBe([$version]);
})->with(['2025-11-25', '2025-06-18', '2025-03-26']);

it('falls back to initialize when the server rejects server/discover with a placeholder id', function (): void {
    $server = FakeMcpServer::at()
        ->respondTo('server/discover', FakeMcpServer::errorWithId('server-error', -32600, 'Bad Request: Unsupported protocol version', status: 400))
        ->withTools([['name' => 'search']]);

    $tools = resolve(DownstreamClient::class)->session(Connection::factory()->create())->listTools();

    expect($tools)->toHaveCount(1)
        ->and($server->received('initialize'))->toHaveCount(1);
});

it('falls back to initialize when the server rejects server/discover with an HTTP status', function (): void {
    FakeMcpServer::at()
        ->respondTo('server/discover', FakeMcpServer::httpStatus(405))
        ->withTools([['name' => 'search']]);

    $tools = resolve(DownstreamClient::class)->session(Connection::factory()->create())->listTools();

    expect($tools)->toHaveCount(1);
});

it('refuses a server that settles on a protocol version Nexus does not speak', function (): void {
    FakeMcpServer::at()->speaking('2024-11-05');

    $failure = downstreamFailure(Connection::factory()->create(), fn (DownstreamSession $session): array => $session->listTools());

    expect($failure->failure)->toBe(DownstreamFailure::ProtocolError)
        ->and($failure->getMessage())->toBe('The server speaks an MCP protocol version Nexus does not support.');
});

it('follows tools/list cursors until the last page', function (): void {
    $server = FakeMcpServer::at()->paginate(2)->withTools([
        ['name' => 'one'], ['name' => 'two'], ['name' => 'three'], ['name' => 'four'], ['name' => 'five'],
    ]);

    $tools = resolve(DownstreamClient::class)->session(Connection::factory()->create())->listTools();

    expect(array_map(fn (string $tool): string => json_decode($tool)->name, $tools))->toBe(['one', 'two', 'three', 'four', 'five'])
        ->and(array_map(fn (stdClass $message): ?string => $message->params->cursor ?? null, $server->received('tools/list')))
        ->toBe([null, '2', '4']);
});

it('stops listing when the server repeats a cursor', function (): void {
    FakeMcpServer::at()->respondTo('tools/list', fn (stdClass $message): PromiseInterface => Http::response(
        json_encode(['jsonrpc' => '2.0', 'id' => $message->id, 'result' => ['tools' => [['name' => 'search']], 'nextCursor' => 'again']]),
    ));

    $failure = downstreamFailure(Connection::factory()->create(), fn (DownstreamSession $session): array => $session->listTools());

    expect($failure->failure)->toBe(DownstreamFailure::ProtocolError);
});

it('reads answers sent as server-sent events', function (bool $splitData): void {
    FakeMcpServer::at()->streaming($splitData)->withTools('[{"name":"search","inputSchema":{"type":"object","properties":{}}}]');

    $tools = resolve(DownstreamClient::class)->session(Connection::factory()->create())->listTools();

    expect($tools)->toBe(['{"name":"search","inputSchema":{"type":"object","properties":{}}}']);
})->with([
    'one data line per event' => false,
    'data over several lines, with CRLF, a comment and an id' => true,
]);

it('keeps every digit of the numbers in a tool, however long or large', function (): void {
    $tool = '{"name":"calc","inputSchema":{"type":"object","properties":{"n":{"type":"number","maximum":1e400,"default":12345678901234567890,"multipleOf":0.10000000000000001}}}}';
    FakeMcpServer::at()->withTools("[{$tool}]");

    $tools = resolve(DownstreamClient::class)->session(Connection::factory()->create())->listTools();

    expect($tools)->toBe([$tool]);
});

it('keeps the last of two tools listed under the same name', function (): void {
    FakeMcpServer::at()->withTools('[{"name":"search","description":"first"},{"name":"search","description":"second"},{"description":"no name"},"not a tool"]');

    $tools = resolve(DownstreamClient::class)->session(Connection::factory()->create())->listTools();

    expect($tools)->toBe(['{"name":"search","description":"second"}']);
});

it('sends a header sign-in with every request', function (): void {
    $server = FakeMcpServer::at()->requireHeader('X-API-Key', 'sk-test-123')->withTools([['name' => 'search']]);
    $connection = Connection::factory()->withHeader('sk-test-123', 'X-API-Key')->create();

    resolve(DownstreamClient::class)->session($connection)->listTools();

    expect($server->requests())->each(fn ($request) => $request->header('X-API-Key')->toBe(['sk-test-123']));
});

it('waits the connect timeout for the handshake and the call timeout for other requests', function (): void {
    config(['nexus.downstream.connect_timeout' => 3.0, 'nexus.downstream.call_timeout' => 7.0]);
    $server = FakeMcpServer::at()->withTools([['name' => 'search']]);

    resolve(DownstreamClient::class)->session(Connection::factory()->create())->listTools();

    $timeouts = array_map(
        fn (Request $request, array $options): array => [$request->method(), json_decode($request->body())->method ?? null, $options['connect_timeout'], $options['timeout']],
        $server->requests(),
        $server->transferOptions(),
    );

    expect($timeouts)->toBe([
        ['POST', 'server/discover', 3.0, 3.0],
        ['POST', 'initialize', 3.0, 3.0],
        ['POST', 'notifications/initialized', 3.0, 3.0],
        ['POST', 'tools/list', 3.0, 7.0],
        ['DELETE', null, 3.0, 3.0],
    ]);
});

it('gives tool calls 55 seconds by default', function (): void {
    $server = FakeMcpServer::at()->withTools([['name' => 'search']]);

    resolve(DownstreamClient::class)->session(Connection::factory()->create())->callTool('search', '{}');

    $call = collect($server->requests())->search(fn (Request $request): bool => (json_decode($request->body())->method ?? null) === 'tools/call');

    expect($server->transferOptions()[$call])->toMatchArray(['connect_timeout' => 10.0, 'timeout' => 55.0]);
});

it('classifies a failure with a message of its own, never the server\'s text', function (Closure $script, DownstreamFailure $failure, string $message): void {
    $script(FakeMcpServer::at());

    $failed = downstreamFailure(Connection::factory()->create(), fn (DownstreamSession $session): array => $session->listTools());

    expect($failed->failure)->toBe($failure)
        ->and($failed->getMessage())->toBe($message)
        ->and($failed->getPrevious())->toBeNull();
})->with([
    'sign-in required' => [
        fn (FakeMcpServer $server): FakeMcpServer => $server->requireHeader('Authorization', 'Bearer sk-test-123'),
        DownstreamFailure::NeedsSignIn,
        'The server requires sign-in (HTTP 401).',
    ],
    'forbidden' => [
        fn (FakeMcpServer $server): FakeMcpServer => $server->requireHeader('Authorization', 'Bearer sk-test-123', status: 403),
        DownstreamFailure::NeedsSignIn,
        'The server requires sign-in (HTTP 403).',
    ],
    'timeout' => [
        fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('tools/list', FakeMcpServer::timeout()),
        DownstreamFailure::Timeout,
        'The server took too long to answer, so Nexus stopped waiting.',
    ],
    'timeout during the handshake' => [
        fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('server/discover', FakeMcpServer::timeout()),
        DownstreamFailure::Timeout,
        'The server took too long to answer, so Nexus stopped waiting.',
    ],
    'unreachable' => [
        fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('server/discover', FakeMcpServer::unreachable()),
        DownstreamFailure::Unreachable,
        'Nexus could not connect to the server.',
    ],
    'server error' => [
        fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('tools/list', FakeMcpServer::httpStatus(503, 'Downstream secret: sk-live-leak')),
        DownstreamFailure::Unreachable,
        'The server answered with HTTP 503.',
    ],
    'not found' => [
        fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('server/discover', FakeMcpServer::httpStatus(404, 'Downstream secret: sk-live-leak')),
        DownstreamFailure::Unreachable,
        'The server answered with HTTP 404.',
    ],
    'rejected by both handshakes' => [
        fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('server/discover', FakeMcpServer::httpStatus(406))->respondTo('initialize', FakeMcpServer::httpStatus(406, 'Downstream secret: sk-live-leak')),
        DownstreamFailure::ProtocolError,
        'The server rejected Nexus\'s request with HTTP 406.',
    ],
    'JSON-RPC error' => [
        fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('tools/list', FakeMcpServer::error(-32603, 'Downstream secret: sk-live-leak')),
        DownstreamFailure::ProtocolError,
        'The server answered with a JSON-RPC error (code -32603).',
    ],
    'malformed JSON' => [
        fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('tools/list', FakeMcpServer::raw('{"jsonrpc":"2.0","id":4,"result":{"tools":[Downstream secret: sk-live-leak')),
        DownstreamFailure::ProtocolError,
        'The server did not answer like an MCP server.',
    ],
    'not a tool list' => [
        fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('tools/list', FakeMcpServer::raw('{"jsonrpc":"2.0","id":4,"result":{"tools":"Downstream secret: sk-live-leak"}}')),
        DownstreamFailure::ProtocolError,
        'The server did not answer like an MCP server.',
    ],
]);

it('says when the server refuses the credentials Nexus sent', function (): void {
    FakeMcpServer::at()->requireHeader('Authorization', 'Bearer sk-test-good');

    $failed = downstreamFailure(Connection::factory()->withHeader('Bearer sk-test-bad')->create(), fn (DownstreamSession $session): array => $session->listTools());

    expect($failed->failure)->toBe(DownstreamFailure::NeedsSignIn)
        ->and($failed->getMessage())->toBe('The server refused the credentials Nexus sent (HTTP 401).');
});

it('treats a token the server calls invalid as needing sign-in, whatever the status', function (): void {
    FakeMcpServer::at()->respondTo('server/discover', FakeMcpServer::httpStatus(400, 'bad request: Authorization header is badly formatted', [
        'WWW-Authenticate' => 'Bearer error="invalid_token", error_description="Invalid token"',
    ]));

    $failed = downstreamFailure(Connection::factory()->withHeader('Bearer not-a-token')->create(), fn (DownstreamSession $session): array => $session->listTools());

    expect($failed->failure)->toBe(DownstreamFailure::NeedsSignIn)
        ->and($failed->getMessage())->toBe('The server refused the credentials Nexus sent (HTTP 400).');
});

it('keeps the server\'s sign-in challenge', function (): void {
    FakeMcpServer::at()
        ->requireHeader('Authorization', 'Bearer sk-test-123')
        ->challengingWith('Bearer resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource/mcp", scope="read write"');

    $failed = downstreamFailure(Connection::factory()->create(), fn (DownstreamSession $session): array => $session->listTools());

    expect($failed->challenge?->resourceMetadataUrl)->toBe('https://mcp.example.com/.well-known/oauth-protected-resource/mcp')
        ->and($failed->challenge?->scope)->toBe('read write');
});

it('refuses a server the outbound guard blocks, before anything is sent', function (): void {
    $this->fakeDns(['internal.example.com' => ['10.0.0.8']]);
    $server = FakeMcpServer::at('https://internal.example.com/mcp');

    $failed = downstreamFailure(Connection::factory()->create(['url' => 'https://internal.example.com/mcp']), fn (DownstreamSession $session): array => $session->listTools());

    expect($failed->failure)->toBe(DownstreamFailure::Unreachable)
        ->and($failed->getMessage())->toBe('[internal.example.com] resolves to a private or reserved address.')
        ->and($server->requests())->toBeEmpty();
});

it('calls a tool with its arguments exactly as given and returns the result exactly as sent', function (): void {
    $server = FakeMcpServer::at()->withTools([['name' => 'search']])->onCall(
        'search',
        fn (): string => '{"content":[{"type":"text","text":"Found it"}],"structuredContent":{"hits":[],"filters":{},"total":12345678901234567890,"score":1e400},"isError":false}',
    );

    $result = resolve(DownstreamClient::class)->session(Connection::factory()->create())
        ->callTool('search', '{"query":"mcp","filters":{},"tags":[],"limit":12345678901234567890,"weight":0.10000000000000001}');

    expect($result)->toBe('{"content":[{"type":"text","text":"Found it"}],"structuredContent":{"hits":[],"filters":{},"total":12345678901234567890,"score":1e400},"isError":false}')
        ->and(sentArguments($server))->toBe('{"query":"mcp","filters":{},"tags":[],"limit":12345678901234567890,"weight":0.10000000000000001}');
});

it('sends empty arguments as an empty object', function (string $version): void {
    $server = FakeMcpServer::at()->speaking($version)->withTools([['name' => 'whoami']]);

    resolve(DownstreamClient::class)->session(Connection::factory()->create())->callTool('whoami', '{}');

    expect(sentArguments($server))->toBe('{}')
        ->and($server->received('tools/call')[0]->params->name)->toBe('whoami');
})->with(['2025-11-25', '2026-07-28']);

it('refuses tool arguments that aren\'t a JSON object, before sending anything', function (string $arguments): void {
    $server = FakeMcpServer::at()->withTools([['name' => 'search']]);

    expect(fn (): string => resolve(DownstreamClient::class)->session(Connection::factory()->create())->callTool('search', $arguments))
        ->toThrow(InvalidArgumentException::class, 'Tool arguments must be a JSON object.');

    expect($server->requests())->toBeEmpty();
})->with(['[]', '"query"', '{"query":', '']);

it('returns a tool result that reports an error, rather than failing', function (): void {
    FakeMcpServer::at()->withTools([['name' => 'search']])->onCall('search', fn (): array => [
        'content' => [['type' => 'text', 'text' => 'No such repository']],
        'isError' => true,
    ]);

    $result = resolve(DownstreamClient::class)->session(Connection::factory()->create())->callTool('search', '{}');

    expect($result)->toBe('{"content":[{"type":"text","text":"No such repository"}],"isError":true}');
});

it('fails a tool call the server answers with a JSON-RPC error, whatever id it carries', function (Closure $responder): void {
    FakeMcpServer::at()->withTools([['name' => 'search']])->respondTo('tools/call', $responder);

    $failed = downstreamFailure(Connection::factory()->create(), fn (DownstreamSession $session): string => $session->callTool('search', '{}'));

    expect($failed->failure)->toBe(DownstreamFailure::ToolError)
        ->and($failed->getMessage())->toBe('The server refused the tool call with a JSON-RPC error (code -32602).');
})->with([
    'its own id' => [FakeMcpServer::error(-32602, 'Unknown tool: search')],
    'a placeholder id' => [FakeMcpServer::errorWithId('server-error', -32602, 'Unknown tool: search')],
    'no id' => [FakeMcpServer::errorWithId(null, -32602, 'Unknown tool: search')],
    'another request\'s id' => [FakeMcpServer::errorWithId(999, -32602, 'Unknown tool: search')],
]);

it('fails a tool call that times out', function (): void {
    FakeMcpServer::at()->withTools([['name' => 'search']])->respondTo('tools/call', FakeMcpServer::timeout());

    $failed = downstreamFailure(Connection::factory()->create(), fn (DownstreamSession $session): string => $session->callTool('search', '{}'));

    expect($failed->failure)->toBe(DownstreamFailure::Timeout);
});

it('reports a handshake failure during a tool call as a protocol error, not a tool error', function (): void {
    FakeMcpServer::at()->withTools([['name' => 'search']])->respondTo('initialize', FakeMcpServer::error(-32603, 'Downstream secret: sk-live-leak'));

    $failed = downstreamFailure(Connection::factory()->create(), fn (DownstreamSession $session): string => $session->callTool('search', '{}'));

    expect($failed->failure)->toBe(DownstreamFailure::ProtocolError)
        ->and($failed->getMessage())->toBe('The server answered with a JSON-RPC error (code -32603).');
});

it('cannot be serialized, since it holds the Connection\'s credentials', function (): void {
    FakeMcpServer::at();

    serialize(resolve(DownstreamClient::class)->session(Connection::factory()->withHeader()->create()));
})->throws(LogicException::class, 'holds secrets, so it cannot be serialized.');
