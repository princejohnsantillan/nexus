<?php

declare(strict_types=1);

use App\Actions\CreateStarToken;
use App\Enums\ActivityStatus;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Tests\Support\ConnectionOAuthFlow;
use Tests\Support\DownstreamCanary;
use Tests\Support\FakeMcpServer;
use Tests\Support\StarClient;

/*
 * A tool call through a Star whose server fails keeps the server's text
 * (DownstreamCanary::TEXT) out of the log, failed jobs and activity, and
 * answers the client with Nexus's own message.
 */

beforeEach(function (): void {
    config(['app.url' => 'https://nexus.test']);
    $this->canary = DownstreamCanary::watch();
    $this->user = User::factory()->create();
});

/**
 * A client of a Star that includes the Connection, with a token of its own.
 */
function toolCallsClientFor(Connection $connection): StarClient
{
    $star = Star::factory()->for($connection->user)->including($connection)->create();

    return StarClient::for($star)->withToken(resolve(CreateStarToken::class)->handle($star, 'Laptop')->plainTextToken);
}

describe('a server that fails', function (): void {
    beforeEach(function (): void {
        $this->server = FakeMcpServer::at()->withTools([['name' => 'search', 'annotations' => ['readOnlyHint' => true]]]);
        $this->connection = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
        ConnectionTool::factory()->for($this->connection)->create(['name' => 'search', 'definition' => '{"name":"search","annotations":{"readOnlyHint":true}}', 'read_only' => true]);
        $this->client = toolCallsClientFor($this->connection);
    });

    it('answers the call with Nexus\'s own message', function (string $method, Closure $answer): void {
        $this->server->respondTo($method, $answer);

        $response = $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

        expect($response->json('result.content.0.text'))->toStartWith('Nexus could not call wiki__search on DeepWiki.')
            ->and($response->getContent())->not->toContain(DownstreamCanary::TEXT)
            ->and(ActivityEntry::query()->sole()->status)->not->toBe(ActivityStatus::Ok)
            ->and($this->canary->sightings())->toBe([]);
    })->with(['initialize', 'tools/call'])->with(DownstreamCanary::failures());

    it('answers a call to a server that offers only versions Nexus doesn\'t speak with Nexus\'s own message', function (): void {
        $this->server->speaking('2026-07-28')->respondTo('server/discover', FakeMcpServer::jsonRpcResult(['supportedVersions' => [DownstreamCanary::TEXT], 'capabilities' => new stdClass]));

        $response = $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

        expect($response->getContent())->not->toContain(DownstreamCanary::TEXT)
            ->and($this->canary->sightings())->toBe([]);
    });

    it('answers a server asking for more input with Nexus\'s own message', function (): void {
        $this->server->respondTo('tools/call', FakeMcpServer::jsonRpcResult(['resultType' => DownstreamCanary::TEXT, 'content' => [['type' => 'text', 'text' => DownstreamCanary::TEXT]]]));

        $response = $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

        expect($response->getContent())->not->toContain(DownstreamCanary::TEXT)
            ->and($this->canary->sightings())->toBe([]);
    });

    it('passes a tool error result to the client unchanged, keeping its text out of the log and activity', function (): void {
        $this->server->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => DownstreamCanary::TEXT]], 'isError' => true]);

        $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.content.0.text', DownstreamCanary::TEXT);

        expect(ActivityEntry::query()->sole()->status)->toBe(ActivityStatus::Error)
            ->and($this->canary->sightings())->toBe([]);
    });

    it('refreshes the catalog of a server that says it doesn\'t know the tool, keeping both answers out of the log', function (): void {
        $this->server
            ->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Unknown tool: search. '.DownstreamCanary::TEXT]], 'isError' => true])
            ->respondTo('tools/list', FakeMcpServer::error(-32000, DownstreamCanary::TEXT));

        $this->client->callTool('wiki__search')->assertOk();

        expect($this->server->received('tools/list'))->toHaveCount(1)
            ->and($this->connection->refresh()->last_error)->toBe('The server answered with a JSON-RPC error (code -32000).')
            ->and($this->canary->sightings())->toBe([]);
    });
});

describe('the Star\'s cached lists', function (): void {
    beforeEach(function (): void {
        $this->server = FakeMcpServer::at()->withTools([['name' => 'search', 'annotations' => ['readOnlyHint' => true]]]);
        $this->connection = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
        ConnectionTool::factory()->for($this->connection)->create([
            'name' => 'search',
            'description' => DownstreamCanary::TEXT,
            'definition' => '{"name":"search","description":"'.DownstreamCanary::TEXT.'","annotations":{"readOnlyHint":true}}',
            'read_only' => true,
        ]);
        $this->client = toolCallsClientFor($this->connection);
    });

    it('answers a call through a cached list with Nexus\'s own message', function (Closure $answer): void {
        $this->client->listTools()->assertOk()->assertJsonPath('result.tools.0.description', DownstreamCanary::TEXT);
        $this->server->respondTo('tools/call', $answer);

        $response = $this->client->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

        expect($response->json('result.content.0.text'))->toStartWith('Nexus could not call wiki__search on DeepWiki.')
            ->and($response->getContent())->not->toContain(DownstreamCanary::PREFIX)
            ->and($this->canary->sightings())->toBe([]);
    })->with(DownstreamCanary::someFailures());

    it('lists the tools when the database cache refuses them, reporting it without the server\'s text', function (): void {
        config(['cache.default' => 'database']);
        DB::unprepared("CREATE TRIGGER refuse_tool_lists BEFORE INSERT ON cache WHEN NEW.key LIKE '%.tools' BEGIN SELECT RAISE(ABORT, 'refused'); END");
        $logged = [];
        Event::listen(function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message;
        });

        $this->client->listTools()->assertOk()->assertJsonPath('result.tools.0.description', DownstreamCanary::TEXT);

        expect($logged)->toBe(['Nexus could not cache the tools list of Star '.$this->connection->stars()->sole()->id.' (SQLSTATE 23000).'])
            ->and($this->canary->sightings())->toBe([]);
    })->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'Only local SQLite runs the database cache; on Postgres the refused write would abort the test\'s transaction.');
});

it('answers a call whose credentials can\'t go in a header with Nexus\'s own message', function (): void {
    FakeMcpServer::at()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->connected()->withHeader('Bearer '.DownstreamCanary::TEXT."\nX-Injected: yes")->create(['name' => 'Legacy', 'handle' => 'legacy']);
    ConnectionTool::factory()->for($connection)->create(['name' => 'search', 'definition' => '{"name":"search","annotations":{"readOnlyHint":true}}', 'read_only' => true]);

    $response = toolCallsClientFor($connection)->callTool('legacy__search')->assertOk()->assertJsonPath('result.isError', true);

    expect($response->json('result.content.0.text'))->toStartWith('Nexus could not call legacy__search on Legacy. Nexus can\'t send a request with this Connection\'s URL or credentials')
        ->and($response->getContent())->not->toContain(DownstreamCanary::PREFIX)
        ->and(ActivityEntry::query()->sole()->status)->toBe(ActivityStatus::NeedsAuth)
        ->and($this->canary->sightings())->toBe([]);
});

describe('a sign-in the server won\'t renew', function (): void {
    beforeEach(function (): void {
        $this->freezeTime();
        $this->actingAs($this->user);
        $this->server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search', 'annotations' => ['readOnlyHint' => true]]]);
        $this->connection = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion', 'handle' => 'notion']);
        ConnectionOAuthFlow::signIn($this, $this->connection, $this->server->authorizationServer());
        $this->client = toolCallsClientFor($this->connection);
        $this->travel(2)->hours();
    });

    it('answers the call with Nexus\'s own message', function (Closure $answer): void {
        $this->server->authorizationServer()->respondTo('token', $answer);

        $response = $this->client->callTool('notion__search')->assertOk()->assertJsonPath('result.isError', true);

        expect($response->json('result.content.0.text'))->toStartWith('Nexus could not call notion__search on Notion.')
            ->and($response->getContent())->not->toContain(DownstreamCanary::TEXT)
            ->and($this->connection->refresh()->last_error ?? '')->not->toContain(DownstreamCanary::TEXT)
            ->and($this->canary->sightings())->toBe([]);
    })->with(DownstreamCanary::tokenEndpointFailures());

    it('renews with a token that states an odd lifetime, as a token without one', function (mixed $expiresIn): void {
        $this->server->authorizationServer()->issuingTokensFor(null)->withTokenFields(['expires_in' => $expiresIn, 'workspace_name' => DownstreamCanary::TEXT]);

        $this->client->callTool('notion__search')->assertOk()->assertJsonPath('result.isError', false);

        expect($this->connection->refresh()->secrets->get('expires_at'))->toBeNull()
            ->and(ActivityEntry::query()->sole()->status)->toBe(ActivityStatus::Ok)
            ->and($this->canary->sightings())->toBe([]);
    })->with(DownstreamCanary::oddTokenLifetimes());
});

describe('a session whose time runs out', function (): void {
    beforeEach(function (): void {
        $this->freezeSecond();
        $this->actingAs($this->user);
    });

    it('answers a call whose handshake took the session\'s time with Nexus\'s own timeout message', function (): void {
        FakeMcpServer::at()
            ->withTools([['name' => 'search']])
            ->respondTo('initialize', FakeMcpServer::jsonRpcResult([
                'protocolVersion' => '2025-11-25',
                'capabilities' => ['tools' => new stdClass],
                'serverInfo' => ['name' => DownstreamCanary::TEXT, 'version' => DownstreamCanary::TEXT],
                'instructions' => DownstreamCanary::TEXT,
            ]))
            ->beforeAnswering('initialize', fn () => $this->travel(56)->seconds());
        $connection = Connection::factory()->for($this->user)->connected()->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
        ConnectionTool::factory()->for($connection)->create(['name' => 'search', 'definition' => '{"name":"search","annotations":{"readOnlyHint":true}}', 'read_only' => true]);

        $response = toolCallsClientFor($connection)->callTool('wiki__search')->assertOk()->assertJsonPath('result.isError', true);

        expect($response->json('result.content.0.text'))->toBe('Nexus could not call wiki__search on DeepWiki. The server took too long to answer, so Nexus stopped waiting.')
            ->and(ActivityEntry::query()->sole()->status)->toBe(ActivityStatus::Timeout)
            ->and($this->canary->sightings())->toBe([]);
    });

    describe('while renewing a sign-in', function (): void {
        beforeEach(function (): void {
            $this->server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search', 'annotations' => ['readOnlyHint' => true]]]);
            $this->connection = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion', 'handle' => 'notion']);
            ConnectionOAuthFlow::signIn($this, $this->connection, $this->server->authorizationServer());
            $this->client = toolCallsClientFor($this->connection);
        });

        it('answers a call whose renewal waited for another past the session\'s time with Nexus\'s own timeout message', function (): void {
            Sleep::fake(syncWithCarbon: true);
            $this->travel(3600 - 100)->seconds();
            $this->server->beforeAnswering('initialize', fn () => $this->travel(50)->seconds());
            $lock = Cache::lock("connections.{$this->connection->id}.oauth-tokens", 300);
            $lock->get();

            try {
                $response = $this->client->callTool('notion__search')->assertOk()->assertJsonPath('result.isError', true);
            } finally {
                $lock->release();
            }

            expect($response->json('result.content.0.text'))->toStartWith('Nexus could not call notion__search on Notion. The server took too long to answer')
                ->and(ActivityEntry::query()->sole()->status)->toBe(ActivityStatus::Timeout)
                ->and($this->canary->sightings())->toBe([]);
        });

        it('answers a call whose renewal ran past the session\'s time with Nexus\'s own message', function (Closure $answer): void {
            $this->travel(2)->hours();
            $this->server->authorizationServer()
                ->beforeAnswering('token', fn () => $this->travel(56)->seconds())
                ->respondTo('token', $answer);

            $response = $this->client->callTool('notion__search')->assertOk()->assertJsonPath('result.isError', true);

            expect($response->json('result.content.0.text'))->toStartWith('Nexus could not call notion__search on Notion.')
                ->and($response->getContent())->not->toContain(DownstreamCanary::TEXT)
                ->and($this->connection->refresh()->last_error ?? '')->not->toContain(DownstreamCanary::TEXT)
                ->and(ActivityEntry::query()->sole()->status)->toBeIn([ActivityStatus::Timeout, ActivityStatus::NeedsAuth])
                ->and($this->canary->sightings())->toBe([]);
        })->with(DownstreamCanary::tokenEndpointFailures());
    });
});
