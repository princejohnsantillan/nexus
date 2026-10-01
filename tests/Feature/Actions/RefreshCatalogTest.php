<?php

declare(strict_types=1);

use App\Actions\RefreshCatalog;
use App\Actions\UpdateConnectionServer;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Exceptions\CatalogNotStored;
use App\Models\Connection;
use App\Models\ConnectionTool;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\FakeMcpServer;

it('stores every tool with its definition exactly as received', function (): void {
    $this->freezeSecond();
    FakeMcpServer::at()->withTools('[{"name":"search","title":"Search","description":"Find things.","inputSchema":{"type":"object","properties":{}},"annotations":{"readOnlyHint":true,"openWorldHint":false}}]');
    $connection = Connection::factory()->create();

    $loaded = resolve(RefreshCatalog::class)->handle($connection);

    $definition = '{"name":"search","title":"Search","description":"Find things.","inputSchema":{"type":"object","properties":{}},"annotations":{"readOnlyHint":true,"openWorldHint":false}}';
    expect($loaded)->toBeTrue()
        ->and($connection->tools()->sole()->only(['name', 'title', 'description', 'definition', 'definition_hash', 'read_only', 'destructive', 'idempotent', 'open_world']))->toBe([
            'name' => 'search',
            'title' => 'Search',
            'description' => 'Find things.',
            'definition' => $definition,
            'definition_hash' => hash('sha256', $definition),
            'read_only' => true,
            'destructive' => null,
            'idempotent' => null,
            'open_world' => false,
        ])
        ->and($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Connected, 'last_error' => null])
        ->and($connection->catalog_refreshed_at?->equalTo(now()))->toBeTrue();
});

it('keeps each hint the server declared and leaves the rest not stated', function (mixed $declared, ?bool $stored): void {
    FakeMcpServer::at()->withTools([['name' => 'search', 'annotations' => ['destructiveHint' => $declared]]]);
    $connection = Connection::factory()->create();

    resolve(RefreshCatalog::class)->handle($connection);

    expect($connection->tools()->sole()->destructive)->toBe($stored);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    'not a boolean' => ['yes', null],
    'null' => [null, null],
]);

it('takes the title from the annotations when the tool has none', function (): void {
    FakeMcpServer::at()->withTools([['name' => 'search', 'annotations' => ['title' => 'Search the wiki']]]);
    $connection = Connection::factory()->create();

    resolve(RefreshCatalog::class)->handle($connection);

    expect($connection->tools()->sole()->title)->toBe('Search the wiki');
});

it('rewrites changed tools, keeps unchanged ones and removes vanished ones', function (): void {
    $connection = Connection::factory()->connected()->create();
    $server = FakeMcpServer::at()->withTools([['name' => 'keep', 'description' => 'Same'], ['name' => 'change', 'description' => 'Before'], ['name' => 'vanish']]);
    resolve(RefreshCatalog::class)->handle($connection);
    $kept = $connection->tools()->where('name', 'keep')->sole();
    $this->travel(5)->minutes();

    $server->withTools([['name' => 'keep', 'description' => 'Same'], ['name' => 'change', 'description' => 'After'], ['name' => 'new']]);
    resolve(RefreshCatalog::class)->handle($connection);

    expect($connection->tools()->orderBy('name')->pluck('description', 'name')->all())->toBe(['change' => 'After', 'keep' => 'Same', 'new' => null])
        ->and($connection->tools()->where('name', 'keep')->sole()->updated_at?->equalTo($kept->updated_at))->toBeTrue();
});

it('skips tools whose names the MCP specification does not allow', function (): void {
    FakeMcpServer::at()->withTools([['name' => 'good_name.v2'], ['name' => 'has space'], ['name' => str_repeat('a', 129)], ['name' => '']]);
    $connection = Connection::factory()->create();

    resolve(RefreshCatalog::class)->handle($connection);

    expect($connection->tools()->pluck('name')->all())->toBe(['good_name.v2']);
});

it('keeps the previous catalog and records why when the server can\'t be listed', function (Closure $script, ConnectionStatus $status, string $error): void {
    $this->freezeSecond();
    $connection = Connection::factory()->connected()->create(['catalog_refreshed_at' => now()->subDay()]);
    ConnectionTool::factory()->for($connection)->create(['name' => 'search']);
    $script(FakeMcpServer::at());

    $loaded = resolve(RefreshCatalog::class)->handle($connection);

    expect($loaded)->toBeFalse()
        ->and($connection->tools()->pluck('name')->all())->toBe(['search'])
        ->and($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => $status, 'last_error' => $error])
        ->and($connection->catalog_refreshed_at?->equalTo(now()->subDay()))->toBeTrue();
})->with([
    'sign-in refused' => [
        fn (FakeMcpServer $server): FakeMcpServer => $server->requireHeader('Authorization', 'Bearer sk-test-123'),
        ConnectionStatus::NeedsAuth,
        'The server requires sign-in (HTTP 401).',
    ],
    'server error' => [
        fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('tools/list', FakeMcpServer::httpStatus(503, 'Downstream secret: sk-live-leak')),
        ConnectionStatus::Error,
        'The server answered with HTTP 503.',
    ],
]);

it('clears the last error once the tools load again', function (): void {
    FakeMcpServer::at()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->failed()->create();

    resolve(RefreshCatalog::class)->handle($connection);

    expect($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Connected, 'last_error' => null]);
});

it('stores a definition exactly as received, numbers too long or too large for PHP included', function (): void {
    $definition = '{"name":"calc", "inputSchema":{"type":"object","properties":{"n":{"type":"integer","maximum":18446744073709551615,"exclusiveMaximum":1e400,"multipleOf":0.10000000000000001}}}}';
    FakeMcpServer::at()->withTools("[{$definition}]");
    $connection = Connection::factory()->create();

    $loaded = resolve(RefreshCatalog::class)->handle($connection);

    expect($loaded)->toBeTrue()
        ->and($connection->tools()->sole()->only(['definition', 'definition_hash']))->toBe(['definition' => $definition, 'definition_hash' => hash('sha256', $definition)]);
});

it('drops NUL characters from the title and description it shows', function (): void {
    FakeMcpServer::at()->withTools('[{"name":"search","title":"Sea\\u0000rch","description":"Finds\\u0000 things."}]');
    $connection = Connection::factory()->create();

    resolve(RefreshCatalog::class)->handle($connection);

    expect($connection->tools()->sole()->only(['title', 'description']))->toBe(['title' => 'Search', 'description' => 'Finds things.']);
});

it('drops the outcome when the Connection is deleted while its server is asked, and logs nothing', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });
    $connection = Connection::factory()->create();
    FakeMcpServer::at()
        ->withTools([['name' => 'search', 'description' => 'Downstream secret: sk-live-leak']])
        ->beforeAnswering('tools/list', fn (): mixed => Connection::query()->whereKey($connection->id)->delete());

    $loaded = resolve(RefreshCatalog::class)->handle($connection);

    expect($loaded)->toBeFalse()
        ->and(ConnectionTool::query()->count())->toBe(0)
        ->and($logged)->toBe([]);
});

it('drops the outcome when the Connection moves to another server while its old server is asked', function (Closure $respond): void {
    $connection = Connection::factory()->connected()->create(['url' => 'https://old.example.com/mcp']);
    ConnectionTool::factory()->for($connection)->create(['name' => 'old_tool']);
    $respond(FakeMcpServer::at('https://old.example.com/mcp')->withTools([['name' => 'old_tool'], ['name' => 'another_old_tool']]))
        ->beforeAnswering('tools/list', function () use ($connection): void {
            FakeMcpServer::at('https://new.example.com/mcp')->withTools([['name' => 'new_tool']]);

            resolve(UpdateConnectionServer::class)->handle(Connection::query()->findOrFail($connection->id), [
                'url' => 'https://new.example.com/mcp',
                'auth_type' => ConnectionAuthType::None,
                'header_name' => null,
            ]);
        });

    $loaded = resolve(RefreshCatalog::class)->handle($connection);

    $current = Connection::query()->findOrFail($connection->id);
    expect($loaded)->toBeFalse()
        ->and($current->only(['url', 'status', 'last_error']))->toBe(['url' => 'https://new.example.com/mcp', 'status' => ConnectionStatus::Connected, 'last_error' => null])
        ->and($current->tools()->pluck('name')->all())->toBe(['new_tool']);
})->with([
    'the old server answers' => [fn (FakeMcpServer $server): FakeMcpServer => $server],
    'the old server fails' => [fn (FakeMcpServer $server): FakeMcpServer => $server->respondTo('tools/list', FakeMcpServer::httpStatus(503))],
]);

it('drops the outcome when the Connection changes how it signs in while its server is asked', function (): void {
    $connection = Connection::factory()->connected()->withHeader('Bearer sk-live-123')->create();
    ConnectionTool::factory()->for($connection)->create(['name' => 'kept']);
    FakeMcpServer::at()
        ->withTools([['name' => 'search']])
        ->beforeAnswering('tools/list', fn (): mixed => Connection::query()->whereKey($connection->id)->update(['auth_type' => ConnectionAuthType::None, 'settings' => null, 'secrets' => null]));

    $loaded = resolve(RefreshCatalog::class)->handle($connection);

    expect($loaded)->toBeFalse()
        ->and($connection->tools()->pluck('name')->all())->toBe(['kept']);
});

it('reports a database error without the server\'s text, and records that the tools weren\'t stored', function (): void {
    Exceptions::fake();
    DB::statement("CREATE TRIGGER refuse_tools BEFORE INSERT ON connection_tools BEGIN SELECT RAISE(ABORT, 'refused'); END");
    FakeMcpServer::at()->withTools([['name' => 'search', 'description' => 'Downstream secret: sk-live-leak']]);
    $connection = Connection::factory()->connected()->create();

    $loaded = resolve(RefreshCatalog::class)->handle($connection);

    expect($loaded)->toBeFalse()
        ->and($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Error, 'last_error' => 'Nexus could not store the server\'s tools.'])
        ->and(ConnectionTool::query()->count())->toBe(0);
    Exceptions::assertReported(fn (CatalogNotStored $exception): bool => $exception->getMessage() === "Nexus could not store the catalog of Connection {$connection->id} (SQLSTATE 23000)."
        && ! $exception->getPrevious() instanceof Throwable);
});
