<?php

declare(strict_types=1);

use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\FakeMcpServer;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

/**
 * Match the Flux toast with this text and variant.
 *
 * @return Closure(string, array<string, mixed>): bool
 */
function toastSaying(string $text, string $variant): Closure
{
    return fn (string $event, array $params): bool => $params['slots']['text'] === $text && $params['dataset']['variant'] === $variant;
}

it('shows the Connection\'s status, URL, sign-in, tools and last refresh', function (): void {
    $this->travelTo(now()->subMinutes(20), function (): void {
        $connection = Connection::factory()->for($this->user)->connected()->withHeader('Bearer sk-live-123', 'X-API-Key')
            ->create(['name' => 'DeepWiki', 'handle' => 'deepwiki', 'description' => 'reading docs', 'url' => 'https://mcp.deepwiki.com/mcp']);
        ConnectionTool::factory()->for($connection)->count(2)->create();
    });
    $connection = $this->user->connections()->sole();

    $this->get(route('connections.show', $connection))
        ->assertOk()
        ->assertSee('<title>Connection · Nexus</title>', escape: false)
        ->assertSeeTextInOrder(['DeepWiki', 'Connected', 'deepwiki', 'Use for: reading docs', 'Overview', 'Tools'])
        ->assertSeeTextInOrder(['Status', 'Connected', 'Server URL', 'https://mcp.deepwiki.com/mcp', 'Sign-in', 'Header', 'X-API-Key', 'Tools', '2 tools', 'Last refreshed', '20 minutes ago'])
        ->assertSee(route('connections.tools', $connection))
        ->assertDontSee('sk-live-123');
});

it('shows why the tools didn\'t load', function (): void {
    $connection = Connection::factory()->for($this->user)->failed('The server answered with HTTP 503.')->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->assertSeeText('Nexus couldn\'t load this Connection\'s tools')
        ->assertSeeText('The server answered with HTTP 503.')
        ->assertSeeTextInOrder(['Last refreshed', 'Never']);
});

it('does not find another user\'s Connection', function (string $route): void {
    $connection = Connection::factory()->create();

    $this->get(route($route, $connection))->assertNotFound();
})->with(['connections.show', 'connections.tools']);

it('does not find a Connection by anything but its id', function (): void {
    $this->get('/connections/deepwiki')->assertNotFound();
});

it('sends guests to the welcome page', function (string $route): void {
    $connection = Connection::factory()->for($this->user)->create();
    auth()->logout();

    $this->get(route($route, $connection))->assertRedirect(route('home'));
})->with(['connections.show', 'connections.tools']);

it('edits the name and the "use this account for" note', function (): void {
    $connection = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki', 'description' => null]);

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->set('name', 'DeepWiki docs')
        ->set('description', 'reading docs')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', toastSaying('Saved.', 'success'));

    expect($connection->refresh()->only(['name', 'description']))->toBe(['name' => 'DeepWiki docs', 'description' => 'reading docs']);
});

it('requires a name', function (): void {
    $connection = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki']);

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->set('name', '  ')
        ->call('saveDetails')
        ->assertHasErrors(['name' => 'required']);

    expect($connection->refresh()->name)->toBe('DeepWiki');
});

it('replaces the header value and reloads the tools with it', function (): void {
    $server = FakeMcpServer::at()->requireHeader('Authorization', 'Bearer sk-live-new')->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->withHeader('Bearer sk-live-old')->failed()->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->assertSet('headerValue', '')
        ->set('headerValue', 'Bearer sk-live-new')
        ->call('saveServer')
        ->assertHasNoErrors()
        ->assertSet('headerValue', '')
        ->assertDispatched('toast-show', toastSaying('Saved. Nexus loaded 1 tool.', 'success'));

    expect($connection->refresh()->headerValue())->toBe('Bearer sk-live-new')
        ->and($connection->status)->toBe(ConnectionStatus::Connected)
        ->and($server->requests())->not->toBeEmpty();
});

it('keeps the stored header value when the field is left blank', function (): void {
    FakeMcpServer::at()->requireHeader('Authorization', 'Bearer sk-live-123')->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->withHeader('Bearer sk-live-123')->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->call('saveServer')
        ->assertHasNoErrors();

    expect($connection->refresh()->headerValue())->toBe('Bearer sk-live-123')
        ->and($connection->status)->toBe(ConnectionStatus::Connected);
});

it('asks for the header value again when the URL changes, since stored credentials are cleared', function (): void {
    $connection = Connection::factory()->for($this->user)->withHeader('Bearer sk-live-123')->create(['url' => 'https://old.example.com/mcp']);

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->set('url', 'https://new.example.com/mcp')
        ->call('saveServer')
        ->assertHasErrors(['headerValue' => 'Enter the header value again. Nexus clears stored credentials when the URL changes.']);

    expect($connection->refresh()->url)->toBe('https://old.example.com/mcp')
        ->and($connection->headerValue())->toBe('Bearer sk-live-123');
});

it('moves to a new URL with a new header value, replacing the old server\'s tools', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->withHeader('Bearer sk-live-old')->create(['url' => 'https://old.example.com/mcp']);
    ConnectionTool::factory()->for($connection)->create(['name' => 'old_tool']);
    $old = FakeMcpServer::at('https://old.example.com/mcp');
    FakeMcpServer::at('https://new.example.com/mcp')->requireHeader('Authorization', 'Bearer sk-live-new')->withTools([['name' => 'new_tool']]);

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->set('url', 'https://new.example.com/mcp')
        ->set('headerValue', 'Bearer sk-live-new')
        ->call('saveServer')
        ->assertHasNoErrors();

    expect($connection->refresh()->only(['url', 'status']))->toBe(['url' => 'https://new.example.com/mcp', 'status' => ConnectionStatus::Connected])
        ->and($connection->secrets->all())->toBe(['header_value' => 'Bearer sk-live-new'])
        ->and($connection->tools()->pluck('name')->all())->toBe(['new_tool'])
        ->and($old->requests())->toBeEmpty();
});

it('drops the old server\'s tools even when the new URL fails to load', function (): void {
    $connection = Connection::factory()->for($this->user)->connected()->create(['url' => 'https://old.example.com/mcp']);
    ConnectionTool::factory()->for($connection)->create(['name' => 'old_tool']);
    FakeMcpServer::at('https://new.example.com/mcp')->respondTo('tools/list', FakeMcpServer::httpStatus(503));

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->set('url', 'https://new.example.com/mcp')
        ->call('saveServer')
        ->assertDispatched('toast-show', toastSaying('Saved. Nexus couldn\'t load the tools: The server answered with HTTP 503.', 'danger'));

    expect($connection->refresh()->only(['url', 'status', 'catalog_refreshed_at']))->toBe(['url' => 'https://new.example.com/mcp', 'status' => ConnectionStatus::Error, 'catalog_refreshed_at' => null])
        ->and($connection->tools()->count())->toBe(0);
});

it('refuses a new URL the outbound guard would block', function (): void {
    $connection = Connection::factory()->for($this->user)->create(['url' => 'https://mcp.example.com/mcp']);

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->set('url', 'http://mcp.example.com/mcp')
        ->call('saveServer')
        ->assertHasErrors(['url' => 'Only HTTPS URLs are allowed.']);

    expect($connection->refresh()->url)->toBe('https://mcp.example.com/mcp');
});

it('clears the header when the Connection switches to no auth', function (): void {
    FakeMcpServer::at()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->withHeader('Bearer sk-live-123', 'X-API-Key')->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->set('authType', 'none')
        ->call('saveServer')
        ->assertHasNoErrors();

    expect($connection->refresh()->auth_type)->toBe(ConnectionAuthType::None)
        ->and($connection->settings)->toBeNull()
        ->and($connection->secrets->all())->toBe([]);
});

it('needs a header value to switch to a header sign-in', function (): void {
    $connection = Connection::factory()->for($this->user)->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->set('authType', 'header')
        ->call('saveServer')
        ->assertHasErrors(['headerValue' => 'Enter the header value Nexus should send.']);

    expect($connection->refresh()->auth_type)->toBe(ConnectionAuthType::None);
});

it('refreshes the tools on request', function (): void {
    FakeMcpServer::at()->withTools([['name' => 'search'], ['name' => 'fetch']]);
    $connection = Connection::factory()->for($this->user)->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->call('refreshTools')
        ->assertDispatched('toast-show', toastSaying('Nexus loaded 2 tools.', 'success'))
        ->assertSeeText('2 tools');

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Connected);
});

it('says why a refresh failed', function (): void {
    FakeMcpServer::at()->respondTo('tools/list', FakeMcpServer::timeout());
    $connection = Connection::factory()->for($this->user)->connected()->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->call('refreshTools')
        ->assertDispatched('toast-show', toastSaying('Nexus couldn\'t load the tools: The server took too long to answer, so Nexus stopped waiting.', 'danger'))
        ->assertSeeText('The server took too long to answer, so Nexus stopped waiting.');

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Error);
});

it('says the tools didn\'t load when the Connection is deleted in another tab during a refresh', function (): void {
    $connection = Connection::factory()->for($this->user)->create();
    FakeMcpServer::at()
        ->withTools([['name' => 'search', 'description' => 'Downstream secret: sk-live-leak']])
        ->beforeAnswering('tools/list', fn (): mixed => Connection::query()->whereKey($connection->id)->delete());

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->call('refreshTools')
        ->assertOk()
        ->assertDispatched('toast-show', toastSaying('Nexus couldn\'t load the tools.', 'danger'));

    expect(ConnectionTool::query()->count())->toBe(0);
});

it('deletes the Connection and its tools after confirming', function (): void {
    $connection = Connection::factory()->for($this->user)->create(['name' => 'DeepWiki']);
    ConnectionTool::factory()->for($connection)->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->assertSeeText('Delete DeepWiki?')
        ->call('delete')
        ->assertRedirect(route('connections.index'));

    $this->assertModelMissing($connection);
    expect(ConnectionTool::query()->count())->toBe(0);
    $this->get(route('connections.index'))->assertSeeText('Deleted DeepWiki.');
});
