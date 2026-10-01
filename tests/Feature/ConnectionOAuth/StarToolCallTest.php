<?php

declare(strict_types=1);

use App\Actions\CreateStarToken;
use App\Enums\ActivityStatus;
use App\Enums\ConnectionStatus;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConnectionOAuthFlow;
use Tests\Support\FakeMcpServer;
use Tests\Support\StarClient;

/**
 * An MCP client of a Star that includes the Connection, with a token of its own.
 */
function starClientFor(User $user, Connection $connection): StarClient
{
    $star = Star::factory()->for($user)->including($connection)->create(['name' => 'Work']);

    return StarClient::for($star)->withToken(resolve(CreateStarToken::class)->handle($star, 'Laptop')->plainTextToken);
}

beforeEach(function (): void {
    config(['app.url' => 'https://nexus.test']);
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->server = FakeMcpServer::at()
        ->requireOAuth()
        ->withTools([['name' => 'search', 'annotations' => ['readOnlyHint' => true]]])
        ->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    $this->auth = $this->server->authorizationServer();
    $this->connection = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Notion', 'handle' => 'notion']);

    ConnectionOAuthFlow::signIn($this, $this->connection, $this->auth);
    $this->client = starClientFor($this->user, $this->connection);
});

it('calls a tool through a Star with the Connection\'s access token', function (): void {
    $this->client->callTool('notion__search')
        ->assertOk()
        ->assertJsonPath('result.content.0.text', 'Found it');

    expect(last($this->server->requests())->header('Authorization'))->toBe(['Bearer access-token-1'])
        ->and($this->auth->tokenRequests('refresh_token'))->toBe([]);
});

it('renews an expired access token before calling a tool through a Star, once for calls in a row', function (): void {
    $this->travel(2)->hours();

    $this->client->callTool('notion__search')->assertOk()->assertJsonPath('result.content.0.text', 'Found it');
    $this->client->callTool('notion__search')->assertOk()->assertJsonPath('result.content.0.text', 'Found it');

    $connection = $this->connection->refresh();

    expect($this->auth->tokenRequests('refresh_token'))->toHaveCount(1)
        ->and(last($this->server->requests())->header('Authorization'))->toBe(['Bearer access-token-2'])
        ->and($connection->secrets->get('access_token'))->toBe('access-token-2')
        ->and($connection->secrets->get('refresh_token'))->toBe('refresh-token-2')
        ->and($connection->status)->toBe(ConnectionStatus::Connected)
        ->and(ActivityEntry::query()->pluck('status')->all())->toBe([ActivityStatus::Ok, ActivityStatus::Ok]);
});

it('answers a call whose sign-in the server won\'t renew with the reconnect link, which starts signing in again', function (): void {
    $this->auth->respondTo('token', fn (): PromiseInterface => Http::response(['error' => 'invalid_grant', 'error_description' => 'Secret server details.'], 400));
    $this->travel(2)->hours();
    $requestsBefore = count($this->server->requests());

    $response = $this->client->callTool('notion__search')
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    $reconnect = route('connections.connect', $this->connection);

    expect($response->json('result.content.0.text'))->toBe('Nexus could not call notion__search on Notion. The sign-in expired and the server didn\'t renew it. Reconnect to sign in again. The Notion Connection needs signing in again: ask the user to reconnect it in Nexus at '.$reconnect)
        ->and($reconnect)->toEndWith("/connections/{$this->connection->id}/connect")->toStartWith('http')
        ->and(count($this->server->requests()))->toBe($requestsBefore)
        ->and($this->connection->refresh()->status)->toBe(ConnectionStatus::NeedsAuth)
        ->and(ActivityEntry::query()->sole()->status)->toBe(ActivityStatus::NeedsAuth);

    $this->auth->respondTo('token', fn (): PromiseInterface => Http::response(['error' => 'unused'], 500));

    expect(ConnectionOAuthFlow::start($this, $this->connection))->toStartWith('https://auth.example.com/authorize?');
});

it('answers a call to a Connection that was never signed in with the reconnect link, without calling its server', function (): void {
    $connection = Connection::factory()->for($this->user)->oauth()->create(['name' => 'Linear', 'handle' => 'linear', 'url' => 'https://linear.example.com/mcp']);
    ConnectionTool::factory()->for($connection)->create(['name' => 'list_issues', 'definition' => '{"name":"list_issues","inputSchema":{"type":"object"},"annotations":{"readOnlyHint":true}}', 'read_only' => true]);

    $response = starClientFor($this->user, $connection)->callTool('linear__list_issues')
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    expect($response->json('result.content.0.text'))->toBe('Nexus could not call linear__list_issues on Linear. Nexus isn\'t signed in to this server. Reconnect to sign in. The Linear Connection needs signing in again: ask the user to reconnect it in Nexus at '.route('connections.connect', $connection));
});
