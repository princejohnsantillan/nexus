<?php

declare(strict_types=1);

use App\Actions\RefreshCatalog;
use App\Actions\UpdateConnectionServer;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Exceptions\CatalogNotStored;
use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\ConnectionTool;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\FakeMcpServer;

/**
 * Make the database refuse every new tool with SQLSTATE 23000, as a broken
 * constraint would, on SQLite and on Postgres alike.
 */
function refuseNewTools(): void
{
    if (DB::getDriverName() === 'pgsql') {
        DB::unprepared("CREATE FUNCTION refuse_tools() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'refused' USING ERRCODE = '23000'; END \$\$");
        DB::unprepared('CREATE TRIGGER refuse_tools BEFORE INSERT ON connection_tools FOR EACH ROW EXECUTE FUNCTION refuse_tools()');

        return;
    }

    DB::unprepared("CREATE TRIGGER refuse_tools BEFORE INSERT ON connection_tools BEGIN SELECT RAISE(ABORT, 'refused'); END");
}

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

it('drops the outcome when the Connection\'s header value is replaced while the old one is used', function (Closure $answerOldToken): void {
    $connection = Connection::factory()->connected()->withHeader('Bearer sk-live-old')->create();
    $rotated = false;
    FakeMcpServer::at()
        ->respondTo('tools/list', fn (stdClass $message, Request $request): PromiseInterface => $request->header('Authorization') === ['Bearer sk-live-old']
            ? $answerOldToken($message, $request)
            : FakeMcpServer::jsonRpcResult(['tools' => [['name' => 'new_account_tool']]])($message, $request))
        ->beforeAnswering('tools/list', function () use ($connection, &$rotated): void {
            if ($rotated) {
                return;
            }

            $rotated = true;

            resolve(UpdateConnectionServer::class)->handle(Connection::query()->findOrFail($connection->id), [
                'url' => $connection->url,
                'auth_type' => ConnectionAuthType::Header,
                'header_name' => 'Authorization',
            ], 'Bearer sk-live-new');
        });

    $loaded = resolve(RefreshCatalog::class)->handle($connection);

    $current = Connection::query()->findOrFail($connection->id);
    expect($loaded)->toBeFalse()
        ->and($current->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Connected, 'last_error' => null])
        ->and($current->headerValue())->toBe('Bearer sk-live-new')
        ->and($current->tools()->pluck('name')->all())->toBe(['new_account_tool']);
})->with([
    'the old token still works' => [FakeMcpServer::jsonRpcResult(['tools' => [['name' => 'old_account_tool']]])],
    'the old token is refused' => [FakeMcpServer::httpStatus(401)],
]);

it('does not record a storage failure over a Connection that changed meanwhile', function (): void {
    refuseNewTools();
    $connection = Connection::factory()->connected()->create(['url' => 'https://old.example.com/mcp']);
    FakeMcpServer::at('https://old.example.com/mcp')->withTools([['name' => 'old_tool']]);
    resolve(ExceptionHandler::class)->reportable(function (CatalogNotStored $exception) use ($connection): bool {
        Connection::query()->whereKey($connection->id)->update(['url' => 'https://new.example.com/mcp']);

        return false;
    });

    $loaded = resolve(RefreshCatalog::class)->handle($connection);

    expect($loaded)->toBeFalse()
        ->and(Connection::query()->findOrFail($connection->id)->only(['url', 'status', 'last_error']))
        ->toBe(['url' => 'https://new.example.com/mcp', 'status' => ConnectionStatus::Connected, 'last_error' => null]);
});

it('reports a database error without the server\'s text, and records that the tools weren\'t stored', function (): void {
    Exceptions::fake();
    refuseNewTools();
    FakeMcpServer::at()->withTools([['name' => 'search', 'description' => 'Downstream secret: sk-live-leak']]);
    $connection = Connection::factory()->connected()->create();

    $loaded = resolve(RefreshCatalog::class)->handle($connection);

    expect($loaded)->toBeFalse()
        ->and($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Error, 'last_error' => 'Nexus could not store the server\'s tools.'])
        ->and(ConnectionTool::query()->count())->toBe(0);
    Exceptions::assertReported(fn (CatalogNotStored $exception): bool => $exception->getMessage() === "Nexus could not store the catalog of Connection {$connection->id} (SQLSTATE 23000)."
        && ! $exception->getPrevious() instanceof Throwable);
});

describe('prompts', function (): void {
    it('stores every prompt with its definition exactly as received when the server has prompts', function (): void {
        $definition = '{"name":"summarize","title":"Summarize","description":"Summarize a page.","arguments":[{"name":"page","description":"Which page.","required":true}],"_meta":{}}';
        FakeMcpServer::at()->withTools([['name' => 'search']])->withPrompts("[{$definition}]");
        $connection = Connection::factory()->create();

        $loaded = resolve(RefreshCatalog::class)->handle($connection);

        $prompt = $connection->prompts()->sole();

        expect($loaded)->toBeTrue()
            ->and($prompt->only(['name', 'title', 'description', 'definition', 'definition_hash']))->toBe([
                'name' => 'summarize',
                'title' => 'Summarize',
                'description' => 'Summarize a page.',
                'definition' => $definition,
                'definition_hash' => hash('sha256', $definition),
            ])
            ->and($prompt->arguments())->toBe([['name' => 'page', 'title' => null, 'description' => 'Which page.', 'required' => true]]);
    });

    it('does not ask a server without prompts for them, and forgets the prompts it had', function (): void {
        $server = FakeMcpServer::at()->withTools([['name' => 'search']]);
        $connection = Connection::factory()->connected()->create();
        ConnectionPrompt::factory()->for($connection)->create(['name' => 'gone']);

        $loaded = resolve(RefreshCatalog::class)->handle($connection);

        expect($loaded)->toBeTrue()
            ->and($server->received('prompts/list'))->toBe([])
            ->and($connection->prompts()->count())->toBe(0);
    });

    it('rewrites changed prompts, keeps unchanged ones and removes vanished ones', function (): void {
        $connection = Connection::factory()->connected()->create();
        $server = FakeMcpServer::at()->withPrompts([['name' => 'keep', 'description' => 'Same'], ['name' => 'change', 'description' => 'Before'], ['name' => 'vanish']]);
        resolve(RefreshCatalog::class)->handle($connection);
        $kept = $connection->prompts()->where('name', 'keep')->sole();
        $this->travel(5)->minutes();

        $server->withPrompts([['name' => 'keep', 'description' => 'Same'], ['name' => 'change', 'description' => 'After'], ['name' => 'new']]);
        resolve(RefreshCatalog::class)->handle($connection);

        expect($connection->prompts()->orderBy('name')->pluck('description', 'name')->all())->toBe(['change' => 'After', 'keep' => 'Same', 'new' => null])
            ->and($connection->prompts()->where('name', 'keep')->sole()->updated_at?->equalTo($kept->updated_at))->toBeTrue();
    });

    it('keeps any prompt name up to Nexus\'s limit of 128 characters, skipping longer, empty and invisible ones', function (): void {
        FakeMcpServer::at()->withPrompts([
            ['name' => 'make-this-a-page'], ['name' => 'team:review'], ['name' => 'résumer'], ['name' => 'Ask a question'], ['name' => str_repeat('é', 128)],
            ['name' => ''], ['name' => str_repeat('a', 129)], ['name' => str_repeat('é', 129)], ['name' => "bell\u{7}"], ['name' => "zero\u{200B}width"], ['name' => "new\nline"],
        ]);
        $connection = Connection::factory()->create();

        resolve(RefreshCatalog::class)->handle($connection);

        expect($connection->prompts()->orderBy('id')->pluck('name')->all())->toBe(['make-this-a-page', 'team:review', 'résumer', 'Ask a question', str_repeat('é', 128)]);
    });

    it('stores the prompts of a server that has prompts and no tools, without asking for tools', function (string $version): void {
        $server = FakeMcpServer::at()->speaking($version)->withoutTools()->withPrompts([['name' => 'hello']]);
        $connection = Connection::factory()->failed()->create();

        $loaded = resolve(RefreshCatalog::class)->handle($connection);

        expect($loaded)->toBeTrue()
            ->and($connection->prompts()->pluck('name')->all())->toBe(['hello'])
            ->and($connection->tools()->count())->toBe(0)
            ->and($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Connected, 'last_error' => null])
            ->and($server->received('tools/list'))->toBe([]);
    })->with(['2026-07-28', '2025-11-25']);

    it('still asks a server that declares no capabilities at all for its tools', function (): void {
        $server = FakeMcpServer::at()
            ->withTools([['name' => 'search']])
            ->respondTo('initialize', FakeMcpServer::jsonRpcResult(['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass, 'serverInfo' => ['name' => 'quiet', 'version' => '1.0.0']]));
        $connection = Connection::factory()->create();

        expect(resolve(RefreshCatalog::class)->handle($connection))->toBeTrue()
            ->and($connection->tools()->pluck('name')->all())->toBe(['search'])
            ->and($server->received('prompts/list'))->toBe([]);
    });

    it('keeps the previous prompts, and still stores the tools, when listing prompts fails', function (Closure $responder): void {
        FakeMcpServer::at()->withTools([['name' => 'search']])->withPrompts([['name' => 'new']])->respondTo('prompts/list', $responder);
        $connection = Connection::factory()->failed()->create();
        ConnectionPrompt::factory()->for($connection)->create(['name' => 'kept']);

        $loaded = resolve(RefreshCatalog::class)->handle($connection);

        expect($loaded)->toBeTrue()
            ->and($connection->prompts()->pluck('name')->all())->toBe(['kept'])
            ->and($connection->tools()->pluck('name')->all())->toBe(['search'])
            ->and($connection->refresh()->only(['status', 'last_error']))->toBe(['status' => ConnectionStatus::Connected, 'last_error' => null]);
    })->with([
        'server error' => [FakeMcpServer::httpStatus(503, 'Downstream secret: sk-live-leak')],
        'JSON-RPC error' => [FakeMcpServer::error(-32603, 'Downstream secret: sk-live-leak')],
        'timeout' => [FakeMcpServer::timeout()],
        'malformed' => [FakeMcpServer::jsonRpcResult(['prompts' => 'nope'])],
    ]);

    it('keeps the previous prompts when the tools can\'t be listed', function (): void {
        FakeMcpServer::at()->withPrompts([['name' => 'new']])->respondTo('tools/list', FakeMcpServer::httpStatus(503));
        $connection = Connection::factory()->connected()->create();
        ConnectionPrompt::factory()->for($connection)->create(['name' => 'kept']);

        expect(resolve(RefreshCatalog::class)->handle($connection))->toBeFalse()
            ->and($connection->prompts()->pluck('name')->all())->toBe(['kept']);
    });

    it('drops the prompts too when the Connection moves to another server while its prompts are listed', function (): void {
        $connection = Connection::factory()->connected()->create(['url' => 'https://old.example.com/mcp']);
        FakeMcpServer::at('https://old.example.com/mcp')->withPrompts([['name' => 'old_prompt']])
            ->beforeAnswering('prompts/list', function () use ($connection): void {
                FakeMcpServer::at('https://new.example.com/mcp');

                resolve(UpdateConnectionServer::class)->handle(Connection::query()->findOrFail($connection->id), [
                    'url' => 'https://new.example.com/mcp',
                    'auth_type' => ConnectionAuthType::None,
                    'header_name' => null,
                ]);
            });

        expect(resolve(RefreshCatalog::class)->handle($connection))->toBeFalse()
            ->and(ConnectionPrompt::query()->count())->toBe(0);
    });
});
