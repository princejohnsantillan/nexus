<?php

declare(strict_types=1);

use App\Actions\CreateStarToken;
use App\Actions\SwitchStarTools;
use App\Downstream\RawJson;
use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\NewToolPolicy;
use App\Enums\StarAccessMode;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeMcpServer;
use Tests\Support\StarClient;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->wiki = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
    $this->star = Star::factory()->for($this->user)->including($this->wiki)->create(['name' => 'Work']);
    $this->token = resolve(CreateStarToken::class)->handle($this->star, 'Laptop')->plainTextToken;
    $this->client = StarClient::for($this->star)->withToken($this->token);
});

/**
 * Store a tool in the Connection's catalog exactly as its server listed it.
 */
function catalogTool(Connection $connection, string $definition): ConnectionTool
{
    $decoded = json_decode($definition);

    return ConnectionTool::factory()->for($connection)->create([
        'name' => $decoded->name,
        'definition' => $definition,
        'definition_hash' => hash('sha256', $definition),
        'read_only' => $decoded->annotations->readOnlyHint ?? null,
    ]);
}

/**
 * The response's JSON-RPC result, as the exact JSON sent.
 */
function rawResult(TestResponse $response): string
{
    return RawJson::member($response->getContent() ?: '', 'result') ?? throw new RuntimeException('The response has no result.');
}

/**
 * The text of a tool result's first content block.
 */
function resultText(TestResponse $response): string
{
    return $response->json('result.content.0.text');
}

/**
 * A client with a token for a new Star of the user's that includes these
 * Connections, with every tool on.
 */
function clientForStarIncluding(User $user, Connection ...$connections): StarClient
{
    $star = Star::factory()->for($user)->withPolicy(NewToolPolicy::All)->including(...$connections)->create();

    return StarClient::for($star)->withToken(resolve(CreateStarToken::class)->handle($star, 'Laptop')->plainTextToken);
}

describe('connecting', function (): void {
    it('answers a 2026-07-28 client\'s server/discover as the Star', function (): void {
        $this->client->connect()
            ->assertOk()
            ->assertJsonPath('result.supportedVersions', ['2026-07-28'])
            ->assertJsonPath('result.capabilities', ['tools' => ['listChanged' => false]])
            ->assertJsonPath('result._meta', ['io.modelcontextprotocol/serverInfo' => ['name' => 'Nexus: Work', 'version' => '1.0.0']])
            ->assertJsonPath('result.instructions', fn (string $instructions): bool => str_contains($instructions, '"Work" Star'));
    });

    it('answers a 2025-11-25 client\'s initialize as the Star', function (): void {
        $this->client->speaking('2025-11-25')->connect()
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-11-25')
            ->assertJsonPath('result.capabilities', ['tools' => ['listChanged' => false]])
            ->assertJsonPath('result.serverInfo.name', 'Nexus: Work')
            ->assertJsonPath('result.instructions', fn (string $instructions): bool => str_contains($instructions, '"Work" Star'));
    });

    it('offers no resources or prompts', function (string $method): void {
        $this->client->send($method)
            ->assertNotFound()
            ->assertJsonPath('error.code', -32601);
    })->with(['resources/list', 'prompts/list', 'completion/complete']);

    it('refuses a 2026-07-28 request whose headers do not mirror its body', function (): void {
        $this->client->withHeader('Mcp-Name', 'wiki__other')->callTool('wiki__search')
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32020);
    });
});

describe('tools/list', function (): void {
    it('lists exactly the Star\'s tools that are on, with schemas and annotations as their server sent them', function (string $protocolVersion): void {
        catalogTool($this->wiki, '{"name":"search","inputSchema":{"type":"object","properties":{},"additionalProperties":false},"annotations":{"readOnlyHint":true,"openWorldHint":true},"_meta":{"x":{}}}');
        catalogTool($this->wiki, '{"name":"delete_page","inputSchema":{"type":"object"},"annotations":{"readOnlyHint":false,"destructiveHint":true}}');

        $response = $this->client->speaking($protocolVersion)->listTools()->assertOk();

        expect(rawResult($response))->toStartWith('{"tools":[{"name":"wiki__search","inputSchema":{"type":"object","properties":{},"additionalProperties":false},"annotations":{"readOnlyHint":true,"openWorldHint":true},"_meta":{"x":{}}}]')
            ->and($response->json('result.resultType'))->toBe('complete');
    })->with(['2026-07-28', '2025-11-25']);

    it('follows the Star\'s switches and policy', function (): void {
        catalogTool($this->wiki, '{"name":"search","inputSchema":{"type":"object"},"annotations":{"readOnlyHint":true}}');
        catalogTool($this->wiki, '{"name":"write","inputSchema":{"type":"object"}}');
        resolve(SwitchStarTools::class)->handle($this->star, $this->wiki, false, ['search']);
        resolve(SwitchStarTools::class)->handle($this->star, $this->wiki, true, ['write']);

        $this->client->listTools()->assertJsonPath('result.tools.*.name', ['wiki__write']);
    });

    it('lists no tools of Connections the Star does not include', function (): void {
        $other = Connection::factory()->for($this->user)->create(['handle' => 'other']);
        catalogTool($other, '{"name":"search","inputSchema":{"type":"object"},"annotations":{"readOnlyHint":true}}');
        $this->star->update(['new_tool_policy' => NewToolPolicy::All]);

        $this->client->listTools()->assertJsonPath('result.tools', []);
    });
});

describe('accounts of the same service', function (): void {
    it('starts each tool\'s description with its account when the Star has two GitHub accounts, so agents can tell them apart', function (): void {
        $work = Connection::factory()->for($this->user)->fromConnector('github')->connected()->create(['name' => 'GitHub', 'handle' => 'github', 'account_identity' => 'octocat', 'description' => 'work repositories']);
        $personal = Connection::factory()->for($this->user)->fromConnector('github')->connected()->create(['name' => 'GitHub 2', 'handle' => 'github-2', 'account_identity' => 'hubot']);

        foreach ([$work, $personal] as $connection) {
            catalogTool($connection, '{"name":"get_me","description":"Get details of the authenticated GitHub user.","inputSchema":{"type":"object","properties":{}},"annotations":{"readOnlyHint":true},"_meta":{"x":{}}}');
        }

        $response = clientForStarIncluding($this->user, $work, $personal)->listTools()->assertOk();

        expect(rawResult($response))->toStartWith('{"tools":['
            .'{"name":"github__get_me","description":"From GitHub · octocat — use for: work repositories\n\nGet details of the authenticated GitHub user.","inputSchema":{"type":"object","properties":{}},"annotations":{"readOnlyHint":true},"_meta":{"x":{}}},'
            .'{"name":"github-2__get_me","description":"From GitHub 2 · hubot\n\nGet details of the authenticated GitHub user.","inputSchema":{"type":"object","properties":{}},"annotations":{"readOnlyHint":true},"_meta":{"x":{}}}'
            .']');
    });

    it('labels the accounts of a custom server by its host, leaving a lone Connection\'s descriptions as its server sent them', function (): void {
        $openSource = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki', 'handle' => 'deepwiki', 'url' => 'https://mcp.deepwiki.com/mcp', 'description' => 'open source questions']);
        $mirror = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki 2', 'handle' => 'deepwiki-2', 'url' => 'https://mcp.deepwiki.com/sse']);
        $github = Connection::factory()->for($this->user)->fromConnector('github')->connected()->create(['name' => 'GitHub', 'handle' => 'github', 'account_identity' => 'octocat']);
        catalogTool($openSource, '{"name":"ask_question","inputSchema":{"type":"object"}}');
        catalogTool($mirror, '{"name":"ask_question","description":"","inputSchema":{"type":"object"}}');
        catalogTool($github, '{"name":"get_me","description":"Get details of the authenticated GitHub user.","inputSchema":{"type":"object"}}');

        clientForStarIncluding($this->user, $openSource, $mirror, $github)->listTools()
            ->assertJsonPath('result.tools.*.name', ['deepwiki__ask_question', 'deepwiki-2__ask_question', 'github__get_me'])
            ->assertJsonPath('result.tools.*.description', ['From DeepWiki — use for: open source questions', 'From DeepWiki 2', 'Get details of the authenticated GitHub user.']);
    });

    it('gives a Star\'s only GitHub account no label, whatever the user\'s other Stars include', function (): void {
        $work = Connection::factory()->for($this->user)->fromConnector('github')->connected()->create(['handle' => 'github', 'account_identity' => 'octocat', 'description' => 'work']);
        $personal = Connection::factory()->for($this->user)->fromConnector('github')->connected()->create(['handle' => 'github-2', 'account_identity' => 'hubot']);
        catalogTool($work, '{"name":"get_me","description":"Get details of the authenticated GitHub user.","inputSchema":{"type":"object"}}');
        Star::factory()->for($this->user)->including($work, $personal)->create();

        clientForStarIncluding($this->user, $work)->listTools()
            ->assertJsonPath('result.tools.*.description', ['Get details of the authenticated GitHub user.']);
    });
});

describe('tools/call', function (): void {
    beforeEach(function (): void {
        catalogTool($this->wiki, '{"name":"search","inputSchema":{"type":"object"},"annotations":{"readOnlyHint":true}}');
    });

    it('forwards the arguments exactly as sent and returns the server\'s result exactly as it sent it', function (string $protocolVersion): void {
        $server = FakeMcpServer::at()->onCall('search', fn (): string => '{"content":[{"type":"text","text":"Found it"}],"structuredContent":{"hits":[],"meta":{},"total":18446744073709551615},"isError":false}');

        $response = $this->client->speaking($protocolVersion)->callTool('wiki__search', '{"query":"laravel","filters":{},"limit":1e400}')->assertOk();

        $sent = collect($server->requests())->first(fn (Request $request): bool => (json_decode($request->body())->method ?? null) === 'tools/call');

        expect(RawJson::member(RawJson::member($sent->body(), 'params') ?? '', 'arguments'))->toBe('{"query":"laravel","filters":{},"limit":1e400}')
            ->and($server->received('tools/call')[0]->params->name)->toBe('search')
            ->and(rawResult($response))->toStartWith('{"content":[{"type":"text","text":"Found it"}],"structuredContent":{"hits":[],"meta":{},"total":18446744073709551615},"isError":false,')
            ->and($response->json('result.resultType'))->toBe('complete')
            ->and($response->json('result._meta'))->toBe(['io.modelcontextprotocol/serverInfo' => ['name' => 'Nexus: Work', 'version' => '1.0.0']]);
    })->with(['2026-07-28', '2025-11-25']);

    it('sends {} when the client sends no arguments', function (): void {
        $server = FakeMcpServer::at();

        $this->client->callTool('wiki__search', null)->assertOk();

        expect($server->received('tools/call')[0]->params->arguments)->toEqual(new stdClass);
    });

    it('passes a tool error through as the server sent it', function (): void {
        FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'No such page']], 'isError' => true]);

        $this->client->callTool('wiki__search')
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'No such page');

        expect(ActivityEntry::query()->sole()->status)->toBe(ActivityStatus::Error);
    });

    it('keeps the server\'s own _meta but answers with Nexus\'s server info', function (): void {
        FakeMcpServer::at()->onCall('search', fn (): string => '{"content":[],"_meta":{"trace":"abc","io.modelcontextprotocol/serverInfo":{"name":"downstream","version":"9"}}}');

        $this->client->callTool('wiki__search')
            ->assertOk()
            ->assertJsonPath('result._meta', ['trace' => 'abc', 'io.modelcontextprotocol/serverInfo' => ['name' => 'Nexus: Work', 'version' => '1.0.0']]);
    });

    it('refuses a tool that is switched off, like one the Star does not have', function (string $name): void {
        resolve(SwitchStarTools::class)->handle($this->star, $this->wiki, false, ['search']);
        $server = FakeMcpServer::at();

        $this->client->callTool($name)
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32602)
            ->assertJsonPath('error.message', "Tool [{$name}] not found.");

        expect($server->requests())->toBeEmpty();
    })->with(['switched off' => 'wiki__search', 'unknown tool' => 'wiki__nope', 'unknown handle' => 'nope__search', 'no handle' => 'search']);

    it('refuses arguments that are not an object', function (string $arguments): void {
        $server = FakeMcpServer::at();

        $this->client->callTool('wiki__search', $arguments)
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32602)
            ->assertJsonPath('error.message', 'Invalid params: The [arguments] member must be an object.');

        expect($server->requests())->toBeEmpty();
    })->with(['empty array' => '[]', 'list' => '[1]', 'string' => '"x"', 'null' => 'null']);

    it('refuses a call without a name', function (): void {
        $this->client->speaking('2025-11-25')->send('tools/call', '{"arguments":{}}')
            ->assertStatus(400)
            ->assertJsonPath('error.message', 'Missing [name] parameter.');
    });

    it('answers a server that wants signing in again with where to reconnect', function (): void {
        FakeMcpServer::at()->requireHeader('Authorization', 'Bearer never-sent');

        $response = $this->client->callTool('wiki__search')
            ->assertOk()
            ->assertJsonPath('result.isError', true);

        expect(resultText($response))
            ->toBe('Nexus could not call wiki__search on DeepWiki. The server requires sign-in (HTTP 401). The DeepWiki Connection needs signing in again: ask the user to reconnect it in Nexus at '.url("/connections/{$this->wiki->id}/connect"))
            ->toContain(route('connections.connect', $this->wiki));
    });

    it('says when the server took too long', function (): void {
        FakeMcpServer::at()->respondTo('tools/call', FakeMcpServer::timeout());

        $response = $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

        expect(resultText($response))->toBe('Nexus could not call wiki__search on DeepWiki. The server took too long to answer, so Nexus stopped waiting.');
    });

    it('answers other failures with Nexus\'s own message, never the server\'s text', function (Closure $responder, string $message): void {
        FakeMcpServer::at()->respondTo('tools/call', $responder);

        $response = $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

        expect(resultText($response))->toBe("Nexus could not call wiki__search on DeepWiki. {$message}")
            ->and($response->getContent())->not->toContain('secret downstream text');
    })->with([
        'unreachable' => [FakeMcpServer::unreachable(), 'Nexus could not connect to the server.'],
        'server error' => [FakeMcpServer::httpStatus(503, 'secret downstream text'), 'The server answered with HTTP 503.'],
        'JSON-RPC error' => [FakeMcpServer::error(-32602, 'secret downstream text'), 'The server refused the tool call with a JSON-RPC error (code -32602).'],
    ]);

    it('refuses a result that asks the client for more input', function (): void {
        FakeMcpServer::at()->onCall('search', fn (): array => ['resultType' => 'incomplete', 'inputRequests' => ['ask' => ['method' => 'elicitation/create']]]);

        $response = $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

        expect(resultText($response))->toContain('The server asked for more input before answering, which Nexus does not support yet.')
            ->and($response->json('result.resultType'))->toBe('complete');
    });
});

describe('activity', function (): void {
    beforeEach(function (): void {
        catalogTool($this->wiki, '{"name":"search","inputSchema":{"type":"object"},"annotations":{"readOnlyHint":true}}');
    });

    it('records each call\'s metadata, how the client authenticated and nothing it sent or got back', function (): void {
        FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'the secret result']]]);

        $this->client->callTool('wiki__search', '{"query":"the secret argument"}')->assertOk();

        $entry = ActivityEntry::query()->sole();

        expect($entry->only(['user_id', 'star_id', 'connection_id', 'exposed_name', 'downstream_name', 'client_name']))->toBe([
            'user_id' => $this->user->id,
            'star_id' => $this->star->id,
            'connection_id' => $this->wiki->id,
            'exposed_name' => 'wiki__search',
            'downstream_name' => 'search',
            'client_name' => 'Laptop',
        ])
            ->and($entry->kind)->toBe(ActivityKind::Tool)
            ->and($entry->status)->toBe(ActivityStatus::Ok)
            ->and($entry->via)->toBe(StarAccessMode::Token)
            ->and($entry->duration_ms)->toBeGreaterThanOrEqual(0)
            ->and(json_encode($entry->getAttributes()))->not->toContain('secret');
    });

    it('records calls through the signed URL as made with it, under no client name', function (): void {
        FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
        $this->star->forceFill(['access_mode' => StarAccessMode::SignedUrl])->save();

        StarClient::for($this->star)->at($this->star->signedUrl())->callTool('wiki__search')->assertOk();

        $entry = ActivityEntry::query()->sole();

        expect($entry->via)->toBe(StarAccessMode::SignedUrl)
            ->and($entry->client_name)->toBeNull()
            ->and($entry->status)->toBe(ActivityStatus::Ok);
    });

    it('records how each failed call ended', function (Closure $responder, ActivityStatus $status): void {
        FakeMcpServer::at()->respondTo('tools/call', $responder);

        $this->client->callTool('wiki__search')->assertOk();

        expect(ActivityEntry::query()->sole()->status)->toBe($status);
    })->with([
        'needs sign-in' => [FakeMcpServer::httpStatus(401), ActivityStatus::NeedsAuth],
        'timeout' => [FakeMcpServer::timeout(), ActivityStatus::Timeout],
        'unreachable' => [FakeMcpServer::unreachable(), ActivityStatus::Error],
    ]);

    it('records a call refused for its arguments or its missing name as denied', function (): void {
        $this->client->callTool('wiki__search', '[1]')->assertStatus(400);
        $this->client->speaking('2025-11-25')->send('tools/call', '{"arguments":{}}')->assertStatus(400);

        expect(ActivityEntry::query()->orderBy('id')->get()->map->only(['exposed_name', 'connection_id', 'downstream_name', 'status'])->all())->toBe([
            ['exposed_name' => 'wiki__search', 'connection_id' => $this->wiki->id, 'downstream_name' => 'search', 'status' => ActivityStatus::Denied],
            ['exposed_name' => null, 'connection_id' => null, 'downstream_name' => null, 'status' => ActivityStatus::Denied],
        ]);
    });

    it('returns the result and records the call when the Connection or the Star is deleted while the server answers', function (string $deleted, array $kept): void {
        FakeMcpServer::at()
            ->beforeAnswering('tools/call', fn (): mixed => $this->{$deleted}->delete())
            ->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);

        $this->client->callTool('wiki__search')
            ->assertOk()
            ->assertJsonPath('result.content.0.text', 'Found it');

        expect(ActivityEntry::query()->sole()->only(['user_id', 'star_id', 'connection_id', 'status']))
            ->toBe(['user_id' => $this->user->id, ...$kept, 'status' => ActivityStatus::Ok]);
    })->with([
        'Connection' => ['wiki', fn (): array => ['star_id' => test()->star->id, 'connection_id' => null]],
        'Star' => ['star', fn (): array => ['star_id' => null, 'connection_id' => test()->wiki->id]],
    ]);

    it('returns the result but records nothing when the user is deleted while the server answers', function (): void {
        FakeMcpServer::at()
            ->beforeAnswering('tools/call', fn (): ?bool => $this->user->delete())
            ->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);

        $this->client->callTool('wiki__search')
            ->assertOk()
            ->assertJsonPath('result.content.0.text', 'Found it');

        expect(ActivityEntry::query()->count())->toBe(0);
    });

    it('records a refused call as denied, with its Connection when the tool is only switched off', function (): void {
        resolve(SwitchStarTools::class)->handle($this->star, $this->wiki, false, ['search']);

        $this->client->callTool('wiki__search')->assertStatus(400);
        $this->client->callTool('nope__search')->assertStatus(400);

        expect(ActivityEntry::query()->orderBy('id')->get()->map->only(['exposed_name', 'connection_id', 'downstream_name', 'status'])->all())->toBe([
            ['exposed_name' => 'wiki__search', 'connection_id' => $this->wiki->id, 'downstream_name' => 'search', 'status' => ActivityStatus::Denied],
            ['exposed_name' => 'nope__search', 'connection_id' => null, 'downstream_name' => null, 'status' => ActivityStatus::Denied],
        ]);
    });

    it('keeps the record when the Star and the Connection are deleted', function (): void {
        FakeMcpServer::at();
        $this->client->callTool('wiki__search')->assertOk();

        $this->star->delete();
        $this->wiki->delete();

        expect(ActivityEntry::query()->sole()->only(['user_id', 'star_id', 'connection_id', 'exposed_name']))
            ->toBe(['user_id' => $this->user->id, 'star_id' => null, 'connection_id' => null, 'exposed_name' => 'wiki__search']);
    });

    it('records no activity for listing tools', function (): void {
        $this->client->connect();
        $this->client->listTools();

        expect(ActivityEntry::query()->count())->toBe(0);
    });
});
