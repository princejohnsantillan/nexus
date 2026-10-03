<?php

declare(strict_types=1);

use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\FakeMcpServer;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

it('saves a server without sign-in, loads its tools and opens its page', function (): void {
    FakeMcpServer::at('https://mcp.deepwiki.com/mcp')->withTools([['name' => 'read_wiki_structure'], ['name' => 'read_wiki_contents'], ['name' => 'ask_question']]);

    $component = Livewire::test('pages::connections.add-custom')
        ->set('name', 'DeepWiki')
        ->set('handle', 'deepwiki')
        ->set('description', 'reading docs')
        ->set('url', 'https://mcp.deepwiki.com/mcp')
        ->call('save');

    $connection = $this->user->connections()->sole();
    $component->assertHasNoErrors()->assertRedirect(route('connections.show', $connection));
    expect($connection->only(['name', 'handle', 'description', 'url', 'auth_type', 'status', 'last_error']))->toBe([
        'name' => 'DeepWiki',
        'handle' => 'deepwiki',
        'description' => 'reading docs',
        'url' => 'https://mcp.deepwiki.com/mcp',
        'auth_type' => ConnectionAuthType::None,
        'status' => ConnectionStatus::Connected,
        'last_error' => null,
    ])->and($connection->tools()->count())->toBe(3);

    $this->get(route('connections.show', $connection))->assertOk()->assertSeeText('Connected. Nexus loaded 3 tools.');
});

it('saves a header sign-in, stores its value encrypted and sends it to the server', function (): void {
    FakeMcpServer::at()->requireHeader('X-API-Key', 'sk-live-123')->withTools([['name' => 'search']]);

    Livewire::test('pages::connections.add-custom')
        ->set('name', 'Search')
        ->set('handle', 'search')
        ->set('url', FakeMcpServer::DEFAULT_URL)
        ->set('authType', 'header')
        ->set('headerName', 'X-API-Key')
        ->set('headerValue', 'sk-live-123')
        ->call('save')
        ->assertHasNoErrors();

    $connection = $this->user->connections()->sole();
    expect($connection->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->auth_type)->toBe(ConnectionAuthType::Header)
        ->and($connection->settings)->toBe(['header_name' => 'X-API-Key'])
        ->and($connection->headerValue())->toBe('sk-live-123')
        ->and(DB::table('connections')->value('secrets'))->not->toContain('sk-live-123');
});

it('keeps the Connection with a short error when its tools don\'t load, and logs nothing from the server', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });
    FakeMcpServer::at()->respondTo('tools/list', FakeMcpServer::error(-32603, 'Downstream secret: sk-live-leak'));

    Livewire::test('pages::connections.add-custom')
        ->set('name', 'Broken')
        ->set('handle', 'broken')
        ->set('url', FakeMcpServer::DEFAULT_URL)
        ->call('save')
        ->assertHasNoErrors();

    $connection = $this->user->connections()->sole();
    expect($connection->status)->toBe(ConnectionStatus::Error)
        ->and($connection->last_error)->toBe('The server answered with a JSON-RPC error (code -32603).')
        ->and($logged)->toBe([]);

    $this->get(route('connections.show', $connection))
        ->assertOk()
        ->assertSeeText('Saved, but Nexus couldn\'t load its tools. The Connection page says why.')
        ->assertSeeText('The server answered with a JSON-RPC error (code -32603).')
        ->assertDontSeeText('sk-live-leak');
});

it('requires a name, a handle and a URL', function (): void {
    Livewire::test('pages::connections.add-custom')
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'handle' => 'required', 'url' => 'required']);

    expect(Connection::query()->count())->toBe(0);
});

it('refuses a handle that isn\'t lowercase letters, digits and dashes starting with a letter', function (string $handle): void {
    Livewire::test('pages::connections.add-custom')
        ->set('handle', $handle)
        ->call('save')
        ->assertHasErrors(['handle' => 'Use lowercase letters, digits and dashes, starting with a letter.']);
})->with([
    'uppercase' => 'DeepWiki',
    'starts with a digit' => '1wiki',
    'starts with a dash' => '-wiki',
    'underscore' => 'deep_wiki',
    'space' => 'deep wiki',
]);

it('refuses a handle longer than 24 characters', function (): void {
    Livewire::test('pages::connections.add-custom')
        ->set('handle', str_repeat('a', 25))
        ->call('save')
        ->assertHasErrors(['handle' => 'max']);
});

it('refuses a handle the user already has', function (): void {
    Connection::factory()->for($this->user)->create(['handle' => 'deepwiki']);

    Livewire::test('pages::connections.add-custom')
        ->set('handle', 'deepwiki')
        ->call('save')
        ->assertHasErrors(['handle' => 'You already have a Connection with this handle.']);
});

it('accepts a handle another user already has', function (): void {
    Connection::factory()->create(['handle' => 'deepwiki']);
    FakeMcpServer::at();

    Livewire::test('pages::connections.add-custom')
        ->set('name', 'DeepWiki')
        ->set('handle', 'deepwiki')
        ->set('url', FakeMcpServer::DEFAULT_URL)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->user->connections()->sole()->handle)->toBe('deepwiki');
});

it('refuses a URL the outbound guard would block', function (string $url, string $message): void {
    $this->fakeDns(['internal.example.com' => ['10.0.0.8']]);

    Livewire::test('pages::connections.add-custom')
        ->set('name', 'Internal')
        ->set('handle', 'internal')
        ->set('url', $url)
        ->call('save')
        ->assertHasErrors(['url' => $message]);

    expect(Connection::query()->count())->toBe(0);
})->with([
    'plain HTTP' => ['http://mcp.example.com/mcp', 'Only HTTPS URLs are allowed.'],
    'loopback address' => ['https://127.0.0.1/mcp', '[127.0.0.1] is a private or reserved address.'],
    'host on a private network' => ['https://internal.example.com/mcp', '[internal.example.com] resolves to a private or reserved address.'],
    'not a URL' => ['mcp.example.com', 'That is not a valid URL.'],
]);

it('requires a header name and value for a header sign-in', function (): void {
    Livewire::test('pages::connections.add-custom')
        ->set('authType', 'header')
        ->set('headerName', '')
        ->call('save')
        ->assertHasErrors(['headerName' => 'required', 'headerValue' => 'required']);
});

it('refuses a header name that isn\'t valid or that Nexus sets itself', function (string $name, string $message): void {
    Livewire::test('pages::connections.add-custom')
        ->set('authType', 'header')
        ->set('headerName', $name)
        ->call('save')
        ->assertHasErrors(['headerName' => $message]);
})->with([
    'space' => ['X API Key', 'Enter a header name such as Authorization or X-API-Key, without spaces or a colon.'],
    'colon' => ['X-API-Key:', 'Enter a header name such as Authorization or X-API-Key, without spaces or a colon.'],
    'content type' => ['Content-Type', 'Nexus sets the Content-Type header itself. Choose another header.'],
    'MCP header' => ['Mcp-Session-Id', 'Nexus sets the Mcp-Session-Id header itself. Choose another header.'],
]);

it('refuses a header value with a line break', function (): void {
    Livewire::test('pages::connections.add-custom')
        ->set('authType', 'header')
        ->set('headerValue', "Bearer sk-live-123\r\nX-Injected: yes")
        ->call('save')
        ->assertHasErrors(['headerValue' => 'The header value can\'t contain line breaks or other control characters.']);
});

it('refuses another Connection once the user has as many as an account may', function (): void {
    config(['nexus.plans.free.connections' => 2]);
    Connection::factory()->for($this->user)->count(2)->create();
    $server = FakeMcpServer::at();

    Livewire::test('pages::connections.add-custom')
        ->assertDontSeeText('Connection limit reached')
        ->assertSeeText('You\'ve used both Free Connections')
        ->set('name', 'DeepWiki')
        ->set('handle', 'deepwiki')
        ->set('url', FakeMcpServer::DEFAULT_URL)
        ->call('save')
        ->assertHasErrors(['limit' => 'Free includes 2 Connections. Go Pro for more, or delete one you no longer use.']);

    expect($this->user->connections()->count())->toBe(2)
        ->and($server->requests())->toBeEmpty();
});
