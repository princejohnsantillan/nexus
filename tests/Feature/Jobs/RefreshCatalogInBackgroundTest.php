<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Jobs\RefreshCatalogInBackground;
use App\Models\Connection;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
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

it('queues no second refresh while the first one waits, for up to 30 days', function (): void {
    config(['queue.default' => 'database']);
    $connection = Connection::factory()->create();

    RefreshCatalogInBackground::dispatch($connection->id);
    $this->travel(30)->days();
    $this->travel(-1)->second();
    RefreshCatalogInBackground::dispatch($connection->id);

    expect(DB::table('jobs')->count())->toBe(1);
});

it('queues a refresh again 30 days after one was lost from the queue', function (): void {
    config(['queue.default' => 'database']);
    $connection = Connection::factory()->create();
    RefreshCatalogInBackground::dispatch($connection->id);
    DB::table('jobs')->delete();

    $this->travel(30)->days();
    RefreshCatalogInBackground::dispatch($connection->id);

    expect(DB::table('jobs')->count())->toBe(1);
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

    new RefreshCatalogInBackground($connection->id)->failed(new TimeoutExceededException('App\Jobs\RefreshCatalogInBackground has timed out.'));

    expect($connection->refresh()->only(['status', 'last_error']))->toBe([
        'status' => ConnectionStatus::Error,
        'last_error' => 'Refreshing the tools in the background took longer than 60 seconds, so Nexus stopped.',
    ]);
});

it('records on the Connection a refresh a worker died during, logs nothing, and can queue the next one', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });
    config(['queue.default' => 'database']);
    $server = FakeMcpServer::at();
    $connection = Connection::factory()->connected()->create();
    RefreshCatalogInBackground::dispatch($connection->id);
    DB::table('jobs')->update(['attempts' => 1, 'reserved_at' => now()->getTimestamp()]);
    $this->travel(2)->minutes();

    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    expect($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Error, 'last_error' => 'Nexus could not refresh the tools in the background.'])
        ->and($server->requests())->toBeEmpty()
        ->and($logged)->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0);

    RefreshCatalogInBackground::dispatch($connection->id);

    expect(DB::table('jobs')->count())->toBe(1);
});

it('records a stopped refresh even when the Connection changed in a way that doesn\'t touch its refresh', function (Closure $makeConnection, Closure $change): void {
    $connection = $makeConnection();
    FakeMcpServer::at()->beforeAnswering('tools/list', function () use ($connection, $change): never {
        $change(Connection::query()->findOrFail($connection->id));

        throw new RuntimeException('The worker stopped the refresh.');
    });

    expect(function () use ($connection): void {
        RefreshCatalogInBackground::dispatch($connection->id);
    })->toThrow(RuntimeException::class);

    expect($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Error, 'last_error' => 'Nexus could not refresh the tools in the background.']);
})->with([
    'a new name and note' => [
        fn (): Connection => Connection::factory()->connected()->create(),
        fn (Connection $connection): bool => $connection->update(['name' => 'Renamed', 'description' => 'For work']),
    ],
    'an OAuth access token renewed as it was used' => [
        function (): Connection {
            $connection = Connection::factory()->oauth()->connected()->create();
            $connection->secrets->put(['access_token' => 'access-1', 'expires_at' => now()->addHour()->getTimestamp()]);
            $connection->save();

            return $connection;
        },
        function (Connection $connection): bool {
            $connection->secrets->put(['access_token' => 'access-2']);

            return $connection->save();
        },
    ],
]);

it('leaves the Connection as a newer sign-in or refresh left it', function (Closure $change, array $expected): void {
    $connection = Connection::factory()->withHeader('Bearer old')->connected()->create(['catalog_refreshed_at' => now()->subDay()]);
    FakeMcpServer::at()->beforeAnswering('tools/list', function () use ($connection, $change): never {
        $change(Connection::query()->findOrFail($connection->id));

        throw new RuntimeException('The worker stopped the refresh.');
    });

    expect(function () use ($connection): void {
        RefreshCatalogInBackground::dispatch($connection->id);
    })->toThrow(RuntimeException::class);

    expect($connection->refresh()->only(['status', 'last_error']))->toBe($expected);
})->with([
    'a refresh that loaded the tools' => [
        fn (Connection $connection): bool => $connection->forceFill(['catalog_refreshed_at' => now()])->save(),
        ['status' => ConnectionStatus::Connected, 'last_error' => null],
    ],
    'a refresh that failed' => [
        fn (Connection $connection): bool => $connection->forceFill(['status' => ConnectionStatus::NeedsAuth, 'last_error' => 'The server refused the credentials Nexus sent (HTTP 401).'])->save(),
        ['status' => ConnectionStatus::NeedsAuth, 'last_error' => 'The server refused the credentials Nexus sent (HTTP 401).'],
    ],
    'a new server' => [
        fn (Connection $connection): bool => $connection->forceFill(['url' => 'https://other.example.com/mcp'])->save(),
        ['status' => ConnectionStatus::Connected, 'last_error' => null],
    ],
    'a replaced header' => [
        function (Connection $connection): bool {
            $connection->secrets->put(['header_value' => 'Bearer new']);

            return $connection->save();
        },
        ['status' => ConnectionStatus::Connected, 'last_error' => null],
    ],
]);

it('leaves the Connection as a refresh since it was queued left it, when it never started', function (): void {
    config(['queue.default' => 'database']);
    $connection = Connection::factory()->failed()->create();
    RefreshCatalogInBackground::dispatch($connection->id);
    DB::table('jobs')->update(['attempts' => 1, 'reserved_at' => now()->getTimestamp()]);
    $this->travel(2)->minutes();
    $connection->forceFill(['status' => ConnectionStatus::Connected, 'last_error' => null, 'catalog_refreshed_at' => now()])->save();

    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    expect($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Connected, 'last_error' => null]);
});
