<?php

declare(strict_types=1);

use App\Actions\RefreshCatalog;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\ConnectionTool;
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
