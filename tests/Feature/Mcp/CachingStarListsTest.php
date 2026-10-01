<?php

declare(strict_types=1);

use App\Actions\CreateStarToken;
use App\Actions\UpdateStarConnections;
use App\Enums\NewToolPolicy;
use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use App\Stars\StarInstructions;
use App\Stars\StarPrompts;
use App\Stars\StarToolset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\ConnectionOAuthFlow;
use Tests\Support\FakeMcpServer;
use Tests\Support\StarClient;

beforeEach(function (): void {
    $this->freezeSecond();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->wiki = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
    $this->ask = cachedListTool($this->wiki, 'ask', 'Answers a question.');
    $this->summarize = cachedListPrompt($this->wiki, 'summarize', 'Summarizes a page.');
    $this->star = Star::factory()->for($this->user)->including($this->wiki)->create(['name' => 'Work', 'new_tool_policy' => NewToolPolicy::All]);
    $this->client = clientOf($this->star);
});

/**
 * A tool in the Connection's catalog, as its server listed it.
 */
function cachedListTool(Connection $connection, string $name, string $description): ConnectionTool
{
    $definition = (string) json_encode(['name' => $name, 'description' => $description, 'inputSchema' => ['type' => 'object']]);

    return ConnectionTool::factory()->for($connection)->create([
        'name' => $name,
        'description' => $description,
        'definition' => $definition,
        'definition_hash' => hash('sha256', $definition),
    ]);
}

/**
 * A prompt in the Connection's catalog, as its server listed it.
 */
function cachedListPrompt(Connection $connection, string $name, string $description): ConnectionPrompt
{
    return ConnectionPrompt::factory()->for($connection)->definedAs((string) json_encode(['name' => $name, 'description' => $description]))->create();
}

/**
 * An MCP client of the Star, with a token of its own.
 */
function clientOf(Star $star): StarClient
{
    return StarClient::for($star)->withToken(resolve(CreateStarToken::class)->handle($star, 'Laptop')->plainTextToken);
}

/**
 * The tables read or written while the callback runs.
 *
 * @param  Closure(): void  $callback
 * @return list<string>
 */
function tablesQueriedWhile(Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    preg_match_all('/\b(?:from|join|into|update)\s+"(\w+)"/i', implode("\n", array_column(DB::getQueryLog(), 'query')), $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * The instructions the Star's server sends a client connecting.
 */
function instructionsOf(StarClient $client): string
{
    $instructions = $client->connect()->assertOk()->json('result.instructions');

    return is_string($instructions) ? $instructions : throw new RuntimeException('The server sent no instructions.');
}

/**
 * The description of each tool in a `tools/list` response, by exposed name.
 *
 * @return array<string, string|null>
 */
function listedDescriptions(TestResponse $response): array
{
    $descriptions = [];

    foreach ($response->assertOk()->json('result.tools') as $tool) {
        $descriptions[$tool['name']] = $tool['description'] ?? null;
    }

    return $descriptions;
}

/**
 * The exposed names in a `tools/list` or `prompts/list` response.
 *
 * @return list<string>
 */
function listedNames(TestResponse $response, string $list): array
{
    return $response->assertOk()->json("result.{$list}.*.name");
}

describe('cached reads', function (): void {
    it('lists tools without reading the catalogs or switches again', function (): void {
        $cold = tablesQueriedWhile(function (): void {
            $this->client->listTools()->assertOk();
        });
        $warm = tablesQueriedWhile(function (): void {
            expect(listedNames($this->client->listTools(), 'tools'))->toBe(['wiki__ask']);
        });

        expect($cold)->toContain('connection_tools', 'star_tool_switches')
            ->and($warm)->not->toContain('connection_tools', 'star_tool_switches');
    });

    it('lists prompts without reading the catalogs or switches again', function (): void {
        $cold = tablesQueriedWhile(function (): void {
            $this->client->listPrompts()->assertOk();
        });
        $warm = tablesQueriedWhile(function (): void {
            expect(listedNames($this->client->listPrompts(), 'prompts'))->toBe(['wiki__summarize']);
        });

        expect($cold)->toContain('connection_prompts', 'star_prompt_switches')
            ->and($warm)->not->toContain('connection_prompts', 'star_prompt_switches');
    });

    it('sends the instructions without reading the Star\'s Connections again', function (): void {
        $cold = tablesQueriedWhile(function (): void {
            instructionsOf($this->client);
        });
        $warm = tablesQueriedWhile(function (): void {
            expect(instructionsOf($this->client))->toContain('- wiki: DeepWiki');
        });

        expect($cold)->toContain('connections')
            ->and($warm)->toBe(['stars', 'star_tokens']);
    });

    it('looks up a tool to call in the cache, reading only the Connection it calls', function (): void {
        FakeMcpServer::at()->withTools([['name' => 'ask']])->onCall('ask', fn (): array => ['content' => [['type' => 'text', 'text' => '42']]]);
        $this->client->callTool('wiki__ask')->assertOk();

        $warm = tablesQueriedWhile(function (): void {
            $this->client->callTool('wiki__ask')->assertOk()->assertJsonPath('result.content.0.text', '42');
        });

        expect($warm)->toContain('connections')
            ->not->toContain('connection_tools', 'star_tool_switches');
    });

    it('looks up a prompt to get in the cache, reading only the Connection it asks', function (): void {
        FakeMcpServer::at()->withPrompts([['name' => 'summarize']]);
        $this->client->getPrompt('wiki__summarize')->assertOk();

        $warm = tablesQueriedWhile(function (): void {
            $this->client->getPrompt('wiki__summarize')->assertOk();
        });

        expect($warm)->toContain('connections')
            ->not->toContain('connection_prompts', 'star_prompt_switches');
    });

    it('keeps the Connections\' credentials out of the cache, even encrypted', function (): void {
        $docs = Connection::factory()->for($this->user)->connected()->withHeader('Bearer sk-live-123')->create(['handle' => 'docs']);
        cachedListTool($docs, 'search', 'Searches the docs.');
        cachedListPrompt($docs, 'review', 'Reviews a doc.');
        $this->star->connections()->attach($docs);

        $this->client->listTools()->assertOk();
        $this->client->listPrompts()->assertOk();
        instructionsOf($this->client);

        expect(serialize(Cache::getStore()))
            ->toContain('Searches the docs.', 'Reviews a doc.', '- docs:')
            ->not->toContain('sk-live-123', $docs->getRawOriginal('secrets'));
    });

    it('calls a tool with the Connection\'s current sign-in, which renews after the lists were cached', function (): void {
        $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search']])
            ->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
        $notion = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion', 'handle' => 'notion']);
        ConnectionOAuthFlow::signIn($this, $notion, $server->authorizationServer());
        $this->star->connections()->attach($notion);
        expect(listedNames($this->client->listTools(), 'tools'))->toContain('notion__search');
        $this->travel(59)->minutes();

        $this->client->callTool('notion__search')->assertOk()->assertJsonPath('result.content.0.text', 'Found it');

        expect(last($server->requests())->header('Authorization'))->toBe(['Bearer access-token-2']);
    });

    it('never calls a Connection the Star no longer includes, even while its lists are cached', function (): void {
        FakeMcpServer::at()->withTools([['name' => 'ask']])->withPrompts([['name' => 'summarize']]);
        expect(listedNames($this->client->listTools(), 'tools'))->toBe(['wiki__ask'])
            ->and(listedNames($this->client->listPrompts(), 'prompts'))->toBe(['wiki__summarize']);

        DB::table('connection_star')->where('star_id', $this->star->id)->delete();

        $this->client->callTool('wiki__ask')->assertStatus(400)->assertJsonPath('error.code', -32602);
        $this->client->getPrompt('wiki__summarize')->assertStatus(400)->assertJsonPath('error.code', -32602);
    });

    it('never caches instructions written from a copy of the Star loaded before it was renamed', function (): void {
        $loadedBeforeTheRename = Star::query()->findOrFail($this->star->id);

        Livewire::test('pages::stars.show', ['star' => $this->star])->set('name', 'Research')->call('saveDetails');
        resolve(StarInstructions::class)->for($loadedBeforeTheRename);

        expect(instructionsOf($this->client))->toContain('"Research" Star')->not->toContain('"Work" Star');
    });

    it('forgets the lists of a Star the Connection is added to while it is being deleted', function (): void {
        $other = Star::factory()->for($this->user)->create(['new_tool_policy' => NewToolPolicy::All]);
        $otherClient = clientOf($other);

        Connection::deleting(function (Connection $connection) use ($other): void {
            resolve(UpdateStarConnections::class)->handle($other, [$connection->id]);
            resolve(StarToolset::class)->tools($other);
            resolve(StarPrompts::class)->prompts($other);
            resolve(StarInstructions::class)->for($other);
        });

        Livewire::test('pages::connections.show', ['connection' => $this->wiki])->call('delete');

        expect(listedNames($otherClient->listTools(), 'tools'))->toBe([])
            ->and(listedNames($otherClient->listPrompts(), 'prompts'))->toBe([])
            ->and(instructionsOf($otherClient))->not->toContain('- wiki:');
    });

    it('works the lists out afresh after an hour, even when nothing told it to', function (): void {
        expect(listedNames($this->client->listTools(), 'tools'))->toBe(['wiki__ask']);

        DB::table('connection_tools')->where('id', $this->ask->id)->update(['name' => 'answer']);
        $this->travel(59)->minutes();

        expect(listedNames($this->client->listTools(), 'tools'))->toBe(['wiki__ask']);

        $this->travel(1)->minute();

        expect(listedNames($this->client->listTools(), 'tools'))->toBe(['wiki__answer']);
    });
});

describe('lists worked out afresh after', function (): void {
    it('switching a tool on the Star\'s Tools page', function (): void {
        expect(listedNames($this->client->listTools(), 'tools'))->toBe(['wiki__ask']);

        Livewire::test('pages::stars.tools', ['star' => $this->star])->call('switchTool', $this->ask->id, false);

        expect(listedNames($this->client->listTools(), 'tools'))->toBe([]);
        $this->client->callTool('wiki__ask')->assertStatus(400)->assertJsonPath('error.code', -32602);
    });

    it('switching a Connection\'s tools at once on the Star\'s Tools page', function (): void {
        expect(listedNames($this->client->listTools(), 'tools'))->toBe(['wiki__ask']);

        Livewire::test('pages::stars.tools', ['star' => $this->star])->call('switchConnection', $this->wiki->id, 'off');

        expect(listedNames($this->client->listTools(), 'tools'))->toBe([]);
    });

    it('switching a prompt on the Star\'s Prompts page', function (): void {
        expect(listedNames($this->client->listPrompts(), 'prompts'))->toBe(['wiki__summarize']);

        Livewire::test('pages::stars.prompts', ['star' => $this->star])->call('switchPrompt', $this->summarize->id, false);

        expect(listedNames($this->client->listPrompts(), 'prompts'))->toBe([]);
        $this->client->getPrompt('wiki__summarize')->assertStatus(400)->assertJsonPath('error.code', -32602);
    });

    it('changing the Star\'s new-tool policy', function (): void {
        expect(listedNames($this->client->listTools(), 'tools'))->toBe(['wiki__ask']);

        Livewire::test('pages::stars.tools', ['star' => $this->star])->set('policy', NewToolPolicy::None->value);

        expect(listedNames($this->client->listTools(), 'tools'))->toBe([]);
    });

    it('changing the Star\'s Connections on its overview, labelling accounts of the same service', function (): void {
        $wiki2 = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki 2', 'handle' => 'wiki-2', 'description' => 'public repos']);
        cachedListTool($wiki2, 'ask', 'Answers a question.');
        cachedListPrompt($wiki2, 'summarize', 'Summarizes a page.');
        expect(listedDescriptions($this->client->listTools()))->toBe(['wiki__ask' => 'Answers a question.'])
            ->and(listedNames($this->client->listPrompts(), 'prompts'))->toBe(['wiki__summarize'])
            ->and(instructionsOf($this->client))->not->toContain('wiki-2');

        Livewire::test('pages::stars.show', ['star' => $this->star])
            ->set('connectionIds', [(string) $this->wiki->id, (string) $wiki2->id])
            ->call('saveConnections')
            ->assertHasNoErrors();

        expect(listedDescriptions($this->client->listTools()))->toBe([
            'wiki__ask' => "From DeepWiki\n\nAnswers a question.",
            'wiki-2__ask' => "From DeepWiki 2 — use for: public repos\n\nAnswers a question.",
        ])
            ->and(listedNames($this->client->listPrompts(), 'prompts'))->toBe(['wiki__summarize', 'wiki-2__summarize'])
            ->and(instructionsOf($this->client))->toContain('- wiki-2: DeepWiki 2 — use for: public repos');
    });

    it('refreshing a Connection\'s catalog with "Refresh tools", within the same second', function (): void {
        FakeMcpServer::at()
            ->withTools([['name' => 'ask'], ['name' => 'search']])
            ->withPrompts([['name' => 'summarize'], ['name' => 'review']]);
        expect(listedNames($this->client->listTools(), 'tools'))->toBe(['wiki__ask'])
            ->and(listedNames($this->client->listPrompts(), 'prompts'))->toBe(['wiki__summarize']);

        Livewire::test('pages::connections.show', ['connection' => $this->wiki])->call('refreshTools');

        expect($this->wiki->refresh()->catalog_refreshed_at?->equalTo(now()))->toBeTrue()
            ->and(listedNames($this->client->listTools(), 'tools'))->toBe(['wiki__ask', 'wiki__search'])
            ->and(listedNames($this->client->listPrompts(), 'prompts'))->toBe(['wiki__review', 'wiki__summarize']);
    });

    it('refreshing a stale catalog in the background after a list', function (): void {
        $this->wiki->forceFill(['catalog_refreshed_at' => now()->subDay()])->save();
        FakeMcpServer::at()->withTools([['name' => 'search']]);

        expect(listedNames($this->client->listTools(), 'tools'))->toBe(['wiki__ask'])
            ->and(listedNames($this->client->listTools(), 'tools'))->toBe(['wiki__search']);
    });

    it('renaming a Connection or changing its "use for" note', function (string $field, string $value, string $label): void {
        $wiki2 = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki 2', 'handle' => 'wiki-2']);
        cachedListTool($wiki2, 'ask', 'Answers a question.');
        $this->star->connections()->attach($wiki2);
        $elsewhere = Star::factory()->for($this->user)->including($this->wiki)->create();
        $elsewhereClient = clientOf($elsewhere);
        expect(listedDescriptions($this->client->listTools())['wiki__ask'])->toBe("From DeepWiki\n\nAnswers a question.")
            ->and(instructionsOf($this->client))->toContain('- wiki: DeepWiki')
            ->and(instructionsOf($elsewhereClient))->toContain('- wiki: DeepWiki');

        Livewire::test('pages::connections.show', ['connection' => $this->wiki])
            ->set($field, $value)
            ->call('saveDetails')
            ->assertHasNoErrors();

        expect(listedDescriptions($this->client->listTools())['wiki__ask'])->toBe("From {$label}\n\nAnswers a question.")
            ->and(instructionsOf($this->client))->toContain("- wiki: {$label}")
            ->and(instructionsOf($elsewhereClient))->toContain("- wiki: {$label}");
    })->with([
        'rename' => ['name', 'Team Wiki', 'Team Wiki'],
        'note' => ['description', 'private repos', 'DeepWiki — use for: private repos'],
    ]);

    it('detecting another account when a sign-in renews', function (): void {
        $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search']])
            ->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
        $notion = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion', 'handle' => 'notion']);
        ConnectionOAuthFlow::signIn($this, $notion, $server->authorizationServer());
        $this->star->connections()->attach($notion);
        expect(instructionsOf($this->client))->toContain('- notion: Notion')->not->toContain('BetterWorld');

        $server->authorizationServer()->withTokenFields(['workspace_name' => 'BetterWorld']);
        $this->travel(59)->minutes();
        $this->client->callTool('notion__search')->assertOk();

        expect(instructionsOf($this->client))->toContain('- notion: Notion · BetterWorld');
    });

    it('moving a custom server to another service, which leaves its sibling unlabelled', function (): void {
        FakeMcpServer::at('https://mcp.elsewhere.example.com/mcp')->withTools([['name' => 'ask']]);
        $wiki2 = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki 2', 'handle' => 'wiki-2']);
        cachedListTool($wiki2, 'ask', 'Answers a question.');
        $this->star->connections()->attach($wiki2);
        expect(listedDescriptions($this->client->listTools())['wiki__ask'])->toBe("From DeepWiki\n\nAnswers a question.");

        Livewire::test('pages::connections.show', ['connection' => $wiki2])
            ->set('url', 'https://mcp.elsewhere.example.com/mcp')
            ->call('saveServer')
            ->assertHasNoErrors();

        expect(listedDescriptions($this->client->listTools()))->toBe(['wiki__ask' => 'Answers a question.', 'wiki-2__ask' => null]);
    });

    it('deleting a Connection', function (): void {
        expect(listedNames($this->client->listTools(), 'tools'))->toBe(['wiki__ask'])
            ->and(listedNames($this->client->listPrompts(), 'prompts'))->toBe(['wiki__summarize'])
            ->and(instructionsOf($this->client))->toContain('- wiki: DeepWiki');

        Livewire::test('pages::connections.show', ['connection' => $this->wiki])->call('delete');

        expect(listedNames($this->client->listTools(), 'tools'))->toBe([])
            ->and(listedNames($this->client->listPrompts(), 'prompts'))->toBe([])
            ->and(instructionsOf($this->client))->not->toContain('- wiki:');
        $this->client->callTool('wiki__ask')->assertStatus(400)->assertJsonPath('error.code', -32602);
    });

    it('renaming the Star or changing its description', function (): void {
        expect(instructionsOf($this->client))->toContain('"Work" Star');

        Livewire::test('pages::stars.show', ['star' => $this->star])
            ->set('name', 'Research')
            ->set('description', 'For reading papers.')
            ->call('saveDetails')
            ->assertHasNoErrors();

        expect(instructionsOf($this->client))->toContain('"Research" Star', 'For reading papers.')->not->toContain('"Work" Star');
    });
});
