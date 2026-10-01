<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Jobs\RefreshCatalogInBackground;
use App\Models\Connection;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeMcpServer;

it('refreshes the Connection\'s catalog', function (): void {
    $this->freezeSecond();
    FakeMcpServer::at()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->failed()->create();

    RefreshCatalogInBackground::dispatch($connection->id);

    expect($connection->tools()->pluck('name')->all())->toBe(['search'])
        ->and($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Connected, 'last_error' => null])
        ->and($connection->catalog_refreshed_at?->equalTo(now()))->toBeTrue();
});

it('runs on the database queue', function (): void {
    config(['queue.default' => 'database']);
    FakeMcpServer::at()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->create();

    RefreshCatalogInBackground::dispatch($connection->id);
    expect($connection->tools()->count())->toBe(0);

    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    expect($connection->tools()->pluck('name')->all())->toBe(['search'])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('is tried once and stopped after 60 seconds, well before Laravel Cloud\'s 90-second queue limit', function (): void {
    config(['queue.default' => 'database']);
    $connection = Connection::factory()->create();

    RefreshCatalogInBackground::dispatch($connection->id);

    $payload = json_decode(DB::table('jobs')->value('payload'), true);
    expect($payload)->toMatchArray(['maxTries' => 1, 'timeout' => 60, 'failOnTimeout' => true])
        ->and($payload['timeout'])->toBeLessThan(config()->integer('queue.connections.database.retry_after'));
});

it('waits at most 20 seconds for each request to the server, so a request in flight when it is stopped ends in time', function (): void {
    $server = FakeMcpServer::at()->withTools([['name' => 'search']]);

    RefreshCatalogInBackground::dispatch(Connection::factory()->create()->id);

    $timeouts = array_map(
        fn (Request $request, array $options): array => [json_decode($request->body())->method ?? $request->method(), $options['connect_timeout'], $options['timeout']],
        $server->requests(),
        $server->transferOptions(),
    );

    expect($timeouts)->toBe([
        ['server/discover', 10.0, 10.0],
        ['initialize', 10.0, 10.0],
        ['notifications/initialized', 10.0, 10.0],
        ['tools/list', 10.0, 20.0],
        ['DELETE', 10.0, 10.0],
    ]);
});

it('keeps a configured call timeout shorter than 20 seconds', function (): void {
    config(['nexus.downstream.call_timeout' => 7.0]);
    $server = FakeMcpServer::at()->withTools([['name' => 'search']]);

    RefreshCatalogInBackground::dispatch(Connection::factory()->create()->id);

    $list = collect($server->requests())->search(fn (Request $request): bool => (json_decode($request->body())->method ?? null) === 'tools/list');

    expect($server->transferOptions()[$list])->toMatchArray(['timeout' => 7.0]);
});

it('records a server that can\'t be listed on the Connection, and logs nothing', function (): void {
    Exceptions::fake();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });
    FakeMcpServer::at()->respondTo('tools/list', FakeMcpServer::httpStatus(503, 'Downstream secret: sk-live-leak'));
    $connection = Connection::factory()->connected()->create();

    RefreshCatalogInBackground::dispatch($connection->id);

    expect($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Error, 'last_error' => 'The server answered with HTTP 503.'])
        ->and($logged)->toBe([]);
    Exceptions::assertNothingReported();
});

it('skips a Connection deleted before the refresh runs', function (): void {
    $server = FakeMcpServer::at();
    $connection = Connection::factory()->create();
    $connection->delete();

    RefreshCatalogInBackground::dispatch($connection->id);

    expect($server->requests())->toBeEmpty();
});

it('queues one refresh per Connection at a time', function (): void {
    [$first, $second] = Connection::factory()->count(2)->create();
    Queue::fake([RefreshCatalogInBackground::class]);

    RefreshCatalogInBackground::dispatch($first->id);
    RefreshCatalogInBackground::dispatch($first->id);
    RefreshCatalogInBackground::dispatch($second->id);

    Queue::assertPushed(RefreshCatalogInBackground::class, 2);
    expect(Queue::pushed(RefreshCatalogInBackground::class)->map(fn (RefreshCatalogInBackground $job): int => $job->connectionId)->all())->toBe([$first->id, $second->id]);
});

it('queues the next refresh once the last one has run', function (): void {
    $server = FakeMcpServer::at()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->create();

    RefreshCatalogInBackground::dispatch($connection->id);
    RefreshCatalogInBackground::dispatch($connection->id);

    expect($server->received('tools/list'))->toHaveCount(2);
});

it('records on the Connection a refresh the worker stopped for taking too long', function (): void {
    $connection = Connection::factory()->connected()->create();
    $this->travel(2)->minutes();

    new RefreshCatalogInBackground($connection->id)->failed(new TimeoutExceededException('App\Jobs\RefreshCatalogInBackground has timed out.'));

    expect($connection->refresh()->only(['status', 'last_error']))->toBe([
        'status' => ConnectionStatus::Error,
        'last_error' => 'Refreshing the tools in the background took longer than 60 seconds, so Nexus stopped.',
    ]);
});

it('records on the Connection a refresh the worker gave up on', function (): void {
    Exceptions::fake();
    config(['queue.default' => 'database']);
    $server = FakeMcpServer::at();
    $connection = Connection::factory()->connected()->create();
    RefreshCatalogInBackground::dispatch($connection->id);
    DB::table('jobs')->update(['attempts' => 1]);
    $this->travel(2)->minutes();

    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    expect($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Error, 'last_error' => 'Nexus could not refresh the tools in the background.'])
        ->and($server->requests())->toBeEmpty();
    Exceptions::assertReported(MaxAttemptsExceededException::class);
});

it('leaves a Connection saved while the stopped refresh ran as it is', function (): void {
    $connection = Connection::factory()->connected()->create();
    $this->travel(2)->minutes();
    $connection->update(['name' => 'Renamed']);
    $this->travel(30)->seconds();

    new RefreshCatalogInBackground($connection->id)->failed(new TimeoutExceededException('App\Jobs\RefreshCatalogInBackground has timed out.'));

    expect($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Connected, 'last_error' => null]);
});
