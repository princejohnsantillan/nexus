<?php

declare(strict_types=1);

use App\Actions\CreateStarToken;
use App\Enums\ConnectionStatus;
use App\Enums\NewToolPolicy;
use App\Jobs\RefreshCatalogInBackground;
use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeMcpServer;
use Tests\Support\StarClient;

beforeEach(function (): void {
    $this->freezeSecond();
    $this->user = User::factory()->create();
    $this->wiki = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
    $this->star = Star::factory()->for($this->user)->including($this->wiki)->create(['new_tool_policy' => NewToolPolicy::All]);
    $this->client = StarClient::for($this->star)->withToken(resolve(CreateStarToken::class)->handle($this->star, 'Laptop')->plainTextToken);
});

/**
 * The ids of the Connections whose refresh was queued, in order.
 *
 * @return list<int>
 */
function queuedRefreshes(): array
{
    return Queue::pushed(RefreshCatalogInBackground::class)->map(fn (RefreshCatalogInBackground $job): int => $job->connectionId)->values()->all();
}

describe('tools/list', function (): void {
    it('queues a background refresh of each of the Star\'s Connections whose catalog is stale', function (): void {
        $this->wiki->forceFill(['catalog_refreshed_at' => now()->subHours(6)->subMinute()])->save();
        $fresh = Connection::factory()->for($this->user)->connected()->create(['catalog_refreshed_at' => now()->subHours(5)]);
        $neverLoaded = Connection::factory()->for($this->user)->failed()->create();
        $elsewhere = Connection::factory()->for($this->user)->create();
        $this->star->connections()->attach([$fresh->id, $neverLoaded->id]);
        Queue::fake([RefreshCatalogInBackground::class]);

        $this->client->listTools()->assertOk();

        expect(queuedRefreshes())->toEqualCanonicalizing([$this->wiki->id, $neverLoaded->id]);
    });

    it('takes the stale age from the configuration', function (): void {
        config(['nexus.catalogs.stale_after_minutes' => 30]);
        $this->wiki->forceFill(['catalog_refreshed_at' => now()->subMinutes(31)])->save();
        Queue::fake([RefreshCatalogInBackground::class]);

        $this->client->listTools()->assertOk();

        expect(queuedRefreshes())->toBe([$this->wiki->id]);
    });

    it('serves the catalog as it is, and refreshes it after the response', function (): void {
        $this->wiki->forceFill(['catalog_refreshed_at' => now()->subDay()])->save();
        ConnectionTool::factory()->for($this->wiki)->create(['name' => 'old_tool']);
        FakeMcpServer::at()->withTools([['name' => 'new_tool']]);

        $this->client->listTools()->assertOk()->assertJsonPath('result.tools.*.name', ['wiki__old_tool']);

        expect($this->wiki->tools()->pluck('name')->all())->toBe(['new_tool'])
            ->and($this->wiki->refresh()->catalog_refreshed_at?->equalTo(now()))->toBeTrue();
    });

    it('asks a server whose refresh failed again only once the stale age has passed', function (): void {
        $this->wiki->forceFill(['catalog_refreshed_at' => now()->subDay()])->save();
        $server = FakeMcpServer::at()->respondTo('tools/list', FakeMcpServer::httpStatus(503));

        $this->client->listTools()->assertOk();
        $this->client->listTools()->assertOk();
        $this->travel(6)->hours();
        $this->travel(-1)->second();
        $this->client->listTools()->assertOk();

        expect($server->received('tools/list'))->toHaveCount(1);

        $this->travel(1)->second();
        $this->client->listTools()->assertOk();

        expect($server->received('tools/list'))->toHaveCount(2)
            ->and($this->wiki->refresh()->status)->toBe(ConnectionStatus::Error);
    });

    it('queues nothing when every catalog is fresh', function (): void {
        Queue::fake([RefreshCatalogInBackground::class]);

        $this->client->listTools()->assertOk();

        Queue::assertNotPushed(RefreshCatalogInBackground::class);
    });

    it('queues nothing for a request that is refused', function (): void {
        $this->wiki->forceFill(['catalog_refreshed_at' => now()->subDay()])->save();
        Queue::fake([RefreshCatalogInBackground::class]);

        StarClient::for($this->star)->withToken('nxs_wrong')->listTools()->assertUnauthorized();

        Queue::assertNotPushed(RefreshCatalogInBackground::class);
    });
});

describe('prompts/list', function (): void {
    it('queues a background refresh of each of the Star\'s Connections whose catalog is stale, as tools/list does', function (): void {
        $this->wiki->forceFill(['catalog_refreshed_at' => now()->subHours(6)->subMinute()])->save();
        $fresh = Connection::factory()->for($this->user)->connected()->create(['catalog_refreshed_at' => now()->subHours(5)]);
        $this->star->connections()->attach($fresh);
        Queue::fake([RefreshCatalogInBackground::class]);

        $this->client->listPrompts()->assertOk();

        expect(queuedRefreshes())->toBe([$this->wiki->id]);
    });

    it('serves the prompts as they are, and refreshes them after the response', function (): void {
        $this->wiki->forceFill(['catalog_refreshed_at' => now()->subDay()])->save();
        ConnectionPrompt::factory()->for($this->wiki)->create(['name' => 'old-prompt']);
        FakeMcpServer::at()->withPrompts([['name' => 'new-prompt']]);

        $this->client->listPrompts()->assertOk()->assertJsonPath('result.prompts.*.name', ['wiki__old-prompt']);

        expect($this->wiki->prompts()->pluck('name')->all())->toBe(['new-prompt']);
    });

    it('queues nothing when every catalog is fresh', function (): void {
        Queue::fake([RefreshCatalogInBackground::class]);

        $this->client->listPrompts()->assertOk();

        Queue::assertNotPushed(RefreshCatalogInBackground::class);
    });
});

describe('tools/call', function (): void {
    beforeEach(function (): void {
        ConnectionTool::factory()->for($this->wiki)->create(['name' => 'search']);
    });

    it('queues a background refresh when the server says it doesn\'t know the tool', function (Closure $answer): void {
        $answer(FakeMcpServer::at());
        Queue::fake([RefreshCatalogInBackground::class]);

        $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

        expect(queuedRefreshes())->toBe([$this->wiki->id]);
    })->with([
        'with JSON-RPC "invalid params"' => fn (FakeMcpServer $server): FakeMcpServer => $server,
        'as a tool error (Python SDK)' => fn (FakeMcpServer $server): FakeMcpServer => $server->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Unknown tool: search']], 'isError' => true]),
        'as a tool error (TypeScript SDK)' => fn (FakeMcpServer $server): FakeMcpServer => $server->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'MCP error -32602: Tool search not found']], 'isError' => true]),
        'as a tool error naming no tool' => fn (FakeMcpServer $server): FakeMcpServer => $server->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Error: tool not found.']], 'isError' => true]),
    ]);

    it('queues no refresh when the call fails for another reason', function (Closure $answer): void {
        $answer(FakeMcpServer::at());
        Queue::fake([RefreshCatalogInBackground::class]);

        $this->client->callTool('wiki__search')->assertOk();

        Queue::assertNotPushed(RefreshCatalogInBackground::class);
    })->with([
        'a result' => fn (FakeMcpServer $server): FakeMcpServer => $server->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Unknown tool: search']]]),
        'a tool error' => fn (FakeMcpServer $server): FakeMcpServer => $server->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Repository not found']], 'isError' => true]),
        'another JSON-RPC error' => fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('tools/call', FakeMcpServer::error(-32603, 'Unknown tool: search')),
        'a server error' => fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('tools/call', FakeMcpServer::httpStatus(503, 'Unknown tool: search')),
    ]);

    it('answers the call, then drops the tool the server no longer has', function (): void {
        $server = FakeMcpServer::at()->withTools([['name' => 'ask']]);

        $this->client->callTool('wiki__search')
            ->assertOk()
            ->assertJsonPath('result.content.0.text', 'Nexus could not call wiki__search on DeepWiki. The server refused the tool call with a JSON-RPC error (code -32602).');

        expect($this->wiki->tools()->pluck('name')->all())->toBe(['ask']);

        $this->client->callTool('wiki__search')->assertStatus(400)->assertJsonPath('error.message', 'Tool [wiki__search] not found.');

        expect($server->received('tools/call'))->toHaveCount(1);
    });
});
