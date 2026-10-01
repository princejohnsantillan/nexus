<?php

declare(strict_types=1);

use App\Actions\RefreshCatalog;
use App\Enums\ConnectionStatus;
use App\Jobs\RefreshCatalogInBackground;
use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\DatabaseRefusal;
use Tests\Support\DownstreamCanary;
use Tests\Support\FakeMcpServer;

/*
 * A catalog refresh whose server fails (from the Connection page, when a
 * Connection is added, or on the queue) keeps the server's text
 * (DownstreamCanary::TEXT) out of the log, failed jobs and activity, and
 * records Nexus's own message on the Connection.
 */

beforeEach(function (): void {
    $this->canary = DownstreamCanary::watch();
    $this->user = User::factory()->create();
});

it('says on the Connection page why "Refresh tools" failed, in Nexus\'s words', function (string $method, Closure $answer): void {
    FakeMcpServer::at()->withTools([['name' => 'search']])->respondTo($method, $answer);
    $connection = Connection::factory()->for($this->user)->connected()->create();
    $this->actingAs($this->user);

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->call('refreshTools')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_starts_with($params['slots']['text'], 'Nexus couldn\'t load the tools: ')
            && ! str_contains($params['slots']['text'], DownstreamCanary::TEXT))
        ->assertDontSee(DownstreamCanary::TEXT);

    expect($connection->refresh()->status)->toBeIn([ConnectionStatus::Error, ConnectionStatus::NeedsAuth])
        ->and($connection->last_error)->not->toContain(DownstreamCanary::TEXT)
        ->and($this->canary->sightings())->toBe([]);
})->with(['initialize', 'tools/list'])->with(DownstreamCanary::failures());

it('keeps the prompts when listing them fails', function (Closure $answer): void {
    FakeMcpServer::at()->withTools([['name' => 'search']])->withPrompts([['name' => 'summarize']])->respondTo('prompts/list', $answer);
    $connection = Connection::factory()->for($this->user)->connected()->create();
    ConnectionPrompt::factory()->for($connection)->definedAs('{"name":"summarize"}')->create();

    $loaded = resolve(RefreshCatalog::class)->handle($connection);

    expect($loaded)->toBeTrue()
        ->and($connection->prompts()->pluck('name')->all())->toBe(['summarize'])
        ->and($this->canary->sightings())->toBe([]);
})->with(DownstreamCanary::failures());

it('leaves the account alone when the connector\'s profile tool fails', function (Closure $answer): void {
    FakeMcpServer::at('https://api.githubcopilot.com/mcp/')->withTools([['name' => 'get_me']])->respondTo('tools/call', $answer);
    $connection = Connection::factory()->for($this->user)->fromConnector('github')->withHeader()->create(['account_identity' => 'octocat']);

    $loaded = resolve(RefreshCatalog::class)->handle($connection);

    expect($loaded)->toBeTrue()
        ->and($connection->refresh()->account_identity)->toBe('octocat')
        ->and($this->canary->sightings())->toBe([]);
})->with([
    ...DownstreamCanary::someFailures(),
    'a tool error result' => [FakeMcpServer::jsonRpcResult(['content' => [['type' => 'text', 'text' => DownstreamCanary::TEXT]], 'isError' => true])],
]);

it('reports a database that refuses the catalog without the server\'s text', function (): void {
    DatabaseRefusal::newTools();
    FakeMcpServer::at()->withTools([['name' => 'search', 'title' => DownstreamCanary::TEXT, 'description' => DownstreamCanary::TEXT]]);
    $connection = Connection::factory()->for($this->user)->connected()->create();

    $loaded = resolve(RefreshCatalog::class)->handle($connection);

    expect($loaded)->toBeFalse()
        ->and($connection->refresh()->last_error)->toBe('Nexus could not store the server\'s tools.')
        ->and($this->canary->sightings())->toBe([]);
});

describe('on the queue', function (): void {
    beforeEach(function (): void {
        config(['queue.default' => 'database']);
    });

    it('records a failed refresh on the Connection, failing no job', function (Closure $answer): void {
        FakeMcpServer::at()->withTools([['name' => 'search']])->respondTo('tools/list', $answer);
        $connection = Connection::factory()->for($this->user)->connected()->create();

        RefreshCatalogInBackground::dispatch($connection->id);
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        expect($connection->refresh()->status)->toBeIn([ConnectionStatus::Error, ConnectionStatus::NeedsAuth])
            ->and($connection->last_error)->not->toContain(DownstreamCanary::TEXT)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and($this->canary->sightings())->toBe([]);
    })->with(DownstreamCanary::someFailures());

    it('records a refresh whose session ran out of time on the Connection, failing no job', function (): void {
        $this->freezeSecond();
        FakeMcpServer::at()
            ->withTools([['name' => 'one', 'description' => DownstreamCanary::TEXT], ['name' => 'two', 'description' => DownstreamCanary::TEXT], ['name' => 'three', 'description' => DownstreamCanary::TEXT]])
            ->paginate(1)
            ->beforeAnswering('tools/list', fn () => $this->travel(30)->seconds());
        $connection = Connection::factory()->for($this->user)->connected()->create();

        RefreshCatalogInBackground::dispatch($connection->id);
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        expect($connection->refresh()->last_error)->toBe('The server took too long to answer, so Nexus stopped waiting.')
            ->and($connection->tools()->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and($this->canary->sightings())->toBe([]);
    });

    it('reports a database that refuses the catalog without the server\'s text, failing no job', function (): void {
        DatabaseRefusal::newTools();
        FakeMcpServer::at()->withTools([['name' => 'search', 'title' => DownstreamCanary::TEXT, 'description' => DownstreamCanary::TEXT]]);
        $connection = Connection::factory()->for($this->user)->connected()->create();

        RefreshCatalogInBackground::dispatch($connection->id);
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        expect($connection->refresh()->last_error)->toBe('Nexus could not store the server\'s tools.')
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and($this->canary->sightings())->toBe([]);
    });
});

describe('adding a Connection', function (): void {
    beforeEach(function (): void {
        $this->actingAs($this->user);
    });

    it('tells the user a gallery service refused their token in Nexus\'s words', function (): void {
        FakeMcpServer::at('https://api.githubcopilot.com/mcp/')->respondTo('initialize', FakeMcpServer::httpStatus(401, DownstreamCanary::TEXT, [
            'WWW-Authenticate' => 'Bearer error="invalid_token", error_description="'.DownstreamCanary::TEXT.'"',
        ]));

        Livewire::test('pages::connections.add')
            ->call('startConnecting', 'github')
            ->set('token', 'github_pat_expired')
            ->call('connect')
            ->assertHasErrors('token')
            ->assertDontSee(DownstreamCanary::TEXT);

        expect(Connection::query()->count())->toBe(0)
            ->and($this->canary->sightings())->toBe([]);
    });

    it('keeps a custom server whose tools don\'t load, with Nexus\'s own error', function (Closure $answer): void {
        FakeMcpServer::at()->respondTo('tools/list', $answer);

        Livewire::test('pages::connections.add-custom')
            ->set('name', 'Broken')
            ->set('handle', 'broken')
            ->set('url', FakeMcpServer::DEFAULT_URL)
            ->call('save')
            ->assertHasNoErrors();

        expect($this->user->connections()->sole()->last_error)->not->toContain(DownstreamCanary::TEXT)
            ->and($this->canary->sightings())->toBe([]);
    })->with(DownstreamCanary::someFailures());
});
