<?php

declare(strict_types=1);

use App\Actions\CreateStarToken;
use App\Enums\ActivityStatus;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\Star;
use App\Models\User;
use Tests\Support\DownstreamCanary;
use Tests\Support\FakeMcpServer;
use Tests\Support\StarClient;

/*
 * A prompt fetch through a Star whose server fails keeps the server's text
 * (DownstreamCanary::TEXT) out of the log, failed jobs and activity, and
 * answers the client with Nexus's own JSON-RPC error.
 */

beforeEach(function (): void {
    config(['app.url' => 'https://nexus.test']);
    $this->canary = DownstreamCanary::watch();

    $this->server = FakeMcpServer::at()->withPrompts([['name' => 'summarize']]);
    $connection = Connection::factory()->for(User::factory())->connected()->create(['name' => 'DeepWiki', 'handle' => 'wiki']);
    ConnectionPrompt::factory()->for($connection)->definedAs('{"name":"summarize"}')->create();
    $star = Star::factory()->for($connection->user)->including($connection)->create();
    $this->client = StarClient::for($star)->withToken(resolve(CreateStarToken::class)->handle($star, 'Laptop')->plainTextToken);
});

it('answers a failed fetch with Nexus\'s own JSON-RPC error', function (string $method, Closure $answer): void {
    $this->server->respondTo($method, $answer);

    $response = $this->client->getPrompt('wiki__summarize')->assertJsonPath('error.code', -32603);

    expect($response->json('error.message'))->toStartWith('Nexus could not get wiki__summarize from DeepWiki.')
        ->and($response->getContent())->not->toContain(DownstreamCanary::TEXT)
        ->and(ActivityEntry::query()->sole()->status)->not->toBe(ActivityStatus::Ok)
        ->and($this->canary->sightings())->toBe([]);
})->with(['initialize', 'prompts/get'])->with(DownstreamCanary::failures());

it('answers a fetch through a cached list with Nexus\'s own JSON-RPC error', function (Closure $answer): void {
    $this->client->listPrompts()->assertOk()->assertJsonPath('result.prompts.0.name', 'wiki__summarize');
    $this->server->respondTo('prompts/get', $answer);

    $response = $this->client->getPrompt('wiki__summarize')->assertJsonPath('error.code', -32603);

    expect($response->json('error.message'))->toStartWith('Nexus could not get wiki__summarize from DeepWiki.')
        ->and($response->getContent())->not->toContain(DownstreamCanary::PREFIX)
        ->and($this->canary->sightings())->toBe([]);
})->with(DownstreamCanary::someFailures());

it('answers a server asking for more input with Nexus\'s own message', function (): void {
    $this->server->respondTo('prompts/get', FakeMcpServer::jsonRpcResult(['resultType' => DownstreamCanary::TEXT, 'messages' => []]));

    $response = $this->client->getPrompt('wiki__summarize')->assertJsonPath('error.code', -32603);

    expect($response->getContent())->not->toContain(DownstreamCanary::TEXT)
        ->and($this->canary->sightings())->toBe([]);
});
