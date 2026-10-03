<?php

declare(strict_types=1);

use App\Connectors\ConnectorCatalog;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\ConnectorFixture;
use Tests\Support\FakeMcpServer;

const GITHUB_MCP_URL = 'https://api.githubcopilot.com/mcp/';

/**
 * The gallery's trademark notice, as shown under the cards.
 */
function trademarkNotice(): string
{
    $notice = Livewire::test('pages::connections.index')->instance()->attribution;

    test()->get(route('connections.index'))->assertSeeText($notice);

    return $notice;
}

beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->actingAs($this->user);
});

it('offers a custom server card that leads to the custom form', function (): void {
    $this->get(route('connections.index'))
        ->assertOk()
        ->assertSee('<title>Connections · Nexus</title>', escape: false)
        ->assertSeeText('Custom server')
        ->assertSee(route('connections.add-custom'));
});

it('sends the old Add connection page to the catalog on the Connections page', function (): void {
    $this->get('/connections/add')->assertRedirect('/connections#add-more');

    $this->get(route('connections.index'))
        ->assertOk()
        ->assertSee('id="add-more"', escape: false)
        ->assertSeeHtml('wire:click="startConnecting(\'github\')"');
});

it('shows a card for each connector with its official logo, summary and docs, then the trademark notice', function (): void {
    Connection::factory()->for($this->user)->create();

    $this->get(route('connections.index'))
        ->assertOk()
        ->assertSeeTextInOrder([
            'GitHub', 'Repositories, issues, pull requests, code search and Actions.',
            'Linear', 'Find, create and update issues, projects and comments.',
            'Notion', 'Search, read and edit pages and databases in your workspace.',
            'Custom server',
            'and their logos are trademarks of their respective owners, shown only to identify each service. Nexus is not affiliated with or endorsed by them.',
        ])
        ->assertSee('<svg aria-hidden="true" focusable="false" class="size-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 -1 98 98"><path fill="currentColor"', escape: false)
        ->assertSee('https://github.com/github/github-mcp-server/blob/main/docs/remote-server.md')
        ->assertSeeText('Connecting a service you already use adds another account.');

    expect(trademarkNotice())->toContain('GitHub', 'Linear', 'Notion');
});

it('shows a connector added as one JSON file and one logo, names it in the notice and connects it', function (): void {
    $key = ConnectorFixture::unshippedKey();
    $name = Str::headline($key);
    $url = "https://{$key}.example.com/mcp";
    $directory = sys_get_temp_dir().'/nexus-connectors-'.Str::random(12);
    File::copyDirectory(resource_path('connectors'), $directory);
    File::put("{$directory}/{$key}.json", json_encode([
        'name' => $name,
        'summary' => 'Issues, events and releases from your organization.',
        'url' => $url,
        'docs_url' => 'https://docs.example.com/mcp',
        'registration' => 'automatic',
        'token' => ['console_url' => 'https://example.com/settings/tokens', 'instructions' => 'Create a user auth token.'],
    ]));
    File::put("{$directory}/logos/{$key}.svg", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 72 66"><path fill="currentColor" d="M0 0h72v66H0z"/></svg>');
    $this->app->instance(ConnectorCatalog::class, new ConnectorCatalog($directory));
    FakeMcpServer::at($url)->requireHeader('Authorization', 'Bearer fixture_good')->withTools([['name' => 'find_issues']]);

    try {
        $this->get(route('connections.index'))
            ->assertOk()
            ->assertSeeTextInOrder([$name, 'Issues, events and releases from your organization.', 'Custom server'])
            ->assertSee('<svg aria-hidden="true" focusable="false" class="size-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 72 66">', escape: false);

        expect(trademarkNotice())->toContain('GitHub', 'Linear', 'Notion', $name);

        Livewire::test('pages::connections.index')
            ->assertSeeHtml("wire:click=\"startConnecting('{$key}')\"")
            ->call('startConnecting', $key)
            ->assertSet('name', $name)
            ->assertSet('handle', $key)
            ->assertSet('method', 'oauth')
            ->set('method', 'token')
            ->set('token', 'fixture_good')
            ->call('connect')
            ->assertHasNoErrors();

        $connection = $this->user->connections()->sole();
        expect($connection->connector_key)->toBe($key)
            ->and($connection->status)->toBe(ConnectionStatus::Connected);

        $this->get(route('connections.index'))->assertSee('viewBox="0 0 72 66"', escape: false);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('offers to connect Notion and Linear', function (): void {
    Livewire::test('pages::connections.index')
        ->assertSeeHtml('wire:click="startConnecting(\'github\')"')
        ->assertSeeHtml('wire:click="startConnecting(\'notion\')"')
        ->assertSeeHtml('wire:click="startConnecting(\'linear\')"')
        ->assertDontSeeText('Not available yet');
});

it('refuses to start connecting a connector that doesn\'t exist', function (): void {
    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'missing')
        ->assertNotFound();
});

it('opens the connect modal with a suggested name and handle and the token method chosen', function (): void {
    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->assertDispatched('modal-show', name: 'connect')
        ->assertSet('connectorKey', 'github')
        ->assertSet('name', 'GitHub')
        ->assertSet('handle', 'github')
        ->assertSet('method', 'token')
        ->assertSeeText('Connect GitHub')
        ->assertSeeText('Already connected GitHub? Connecting it again adds another account.')
        ->assertSeeText('Your own token')
        ->assertSeeText('Your own OAuth app')
        ->assertDontSeeText('Not available yet')
        ->assertSeeText('Create a fine-grained personal access token')
        ->assertSee('https://github.com/settings/personal-access-tokens/new')
        ->assertSeeText('Stored encrypted. Nexus sends it as Authorization: Bearer … and checks it by loading the tools.');
});

it('suggests a unique name and handle for another account of the same service', function (): void {
    Connection::factory()->for($this->user)->fromConnector('github')->create(['name' => 'GitHub', 'handle' => 'github']);

    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->assertSet('name', 'GitHub 2')
        ->assertSet('handle', 'github-2');
});

it('skips names and handles the user already has when suggesting', function (): void {
    Connection::factory()->for($this->user)->fromConnector('github')->create(['name' => 'Work', 'handle' => 'github']);
    Connection::factory()->for($this->user)->create(['name' => 'github 2', 'handle' => 'deepwiki']);

    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->assertSet('name', 'GitHub 3')
        ->assertSet('handle', 'github-3');
});

it('doesn\'t let the browser switch the connector being connected', function (): void {
    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->set('connectorKey', 'notion');
})->throws(CannotUpdateLockedPropertyException::class);

it('connects GitHub with a token, stored encrypted and sent as a bearer token, and loads its tools', function (): void {
    $server = FakeMcpServer::at(GITHUB_MCP_URL)
        ->requireHeader('Authorization', 'Bearer github_pat_good')
        ->withTools([['name' => 'get_me', 'annotations' => ['readOnlyHint' => true]], ['name' => 'create_issue']]);

    $component = Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->set('description', 'work repositories')
        ->set('token', ' github_pat_good ')
        ->call('connect');

    $connection = $this->user->connections()->sole();
    $component->assertHasNoErrors()->assertRedirect(route('connections.show', $connection));

    expect($connection->only(['connector_key', 'name', 'handle', 'description', 'url', 'auth_type', 'settings', 'status', 'last_error']))->toBe([
        'connector_key' => 'github',
        'name' => 'GitHub',
        'handle' => 'github',
        'description' => 'work repositories',
        'url' => GITHUB_MCP_URL,
        'auth_type' => ConnectionAuthType::Header,
        'settings' => ['header_name' => 'Authorization'],
        'status' => ConnectionStatus::Connected,
        'last_error' => null,
    ])->and($connection->headerValue())->toBe('Bearer github_pat_good')
        ->and(DB::table('connections')->value('secrets'))->not->toContain('github_pat_good')
        ->and($connection->tools()->pluck('name')->all())->toEqualCanonicalizing(['get_me', 'create_issue'])
        ->and($server->requests()[0]->header('Authorization'))->toBe(['Bearer github_pat_good']);

    $this->get(route('connections.show', $connection))->assertOk()->assertSeeText('Connected. Nexus loaded 2 tools.');
});

it('labels a GitHub Connection made with a token with the login GitHub names', function (): void {
    FakeMcpServer::at(GITHUB_MCP_URL)
        ->requireHeader('Authorization', 'Bearer github_pat_good')
        ->withTools([['name' => 'get_me', 'annotations' => ['readOnlyHint' => true]]])
        ->onCall('get_me', fn (): array => ['content' => [['type' => 'text', 'text' => '{"login":"octocat","id":583231}']]]);

    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->set('token', 'github_pat_good')
        ->call('connect')
        ->assertHasNoErrors();

    $connection = $this->user->connections()->sole();

    expect($connection->account_identity)->toBe('octocat');

    $this->get(route('connections.index'))->assertOk()->assertSeeTextInOrder(['GitHub', 'octocat', 'github']);
});

it('doesn\'t send the prefix twice when the token is pasted with it', function (): void {
    FakeMcpServer::at(GITHUB_MCP_URL)->requireHeader('Authorization', 'Bearer github_pat_good')->withTools([['name' => 'get_me']]);

    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->set('token', 'Bearer github_pat_good')
        ->call('connect')
        ->assertHasNoErrors();

    expect($this->user->connections()->sole()->headerValue())->toBe('Bearer github_pat_good');
});

it('keeps the modal open with a clear error and saves nothing when GitHub refuses the token', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });
    FakeMcpServer::at(GITHUB_MCP_URL)->requireHeader('Authorization', 'Bearer github_pat_good');

    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->set('token', 'github_pat_expired')
        ->call('connect')
        ->assertHasErrors(['token' => 'GitHub didn\'t accept this token. Check that you copied all of it and that it hasn\'t expired or been revoked.'])
        ->assertNoRedirect()
        ->assertSeeText('GitHub didn\'t accept this token.');

    expect(Connection::query()->count())->toBe(0)
        ->and($logged)->toBe([]);
});

it('keeps the Connection with its error when the tools don\'t load for another reason', function (): void {
    FakeMcpServer::at(GITHUB_MCP_URL)->respondTo('tools/list', FakeMcpServer::httpStatus(503));

    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->set('token', 'github_pat_good')
        ->call('connect')
        ->assertHasNoErrors();

    $connection = $this->user->connections()->sole();
    expect($connection->status)->toBe(ConnectionStatus::Error)
        ->and($connection->last_error)->toBe('The server answered with HTTP 503.');

    $this->get(route('connections.show', $connection))
        ->assertOk()
        ->assertSeeText('Saved, but Nexus couldn\'t load its tools. The Connection page says why.');
});

it('requires a name, a handle and a token', function (): void {
    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->set('name', ' ')
        ->set('handle', '')
        ->call('connect')
        ->assertHasErrors(['name' => 'required', 'handle' => 'required', 'token' => 'required']);

    expect(Connection::query()->count())->toBe(0);
});

it('refuses a handle that isn\'t valid or that the user already has', function (string $handle, string $message): void {
    Connection::factory()->for($this->user)->create(['handle' => 'work']);

    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->set('handle', $handle)
        ->set('token', 'github_pat_good')
        ->call('connect')
        ->assertHasErrors(['handle' => $message]);
})->with([
    'uppercase' => ['GitHub', 'Use lowercase letters, digits and dashes, starting with a letter.'],
    'taken' => ['work', 'You already have a Connection with this handle.'],
]);

it('refuses a token with a line break inside it', function (): void {
    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->set('token', "github_pat_good\r\nX-Injected: yes")
        ->call('connect')
        ->assertHasErrors(['token' => 'The token can\'t contain line breaks or other control characters.']);
});

it('offers Pro instead of the catalog once the user has as many Connections as an account may', function (): void {
    config(['nexus.plans.free.connections' => 2]);
    Connection::factory()->for($this->user)->count(2)->create();

    Livewire::test('pages::connections.index')
        ->assertDontSeeText('Connection limit reached')
        ->assertSeeText('You\'ve used both Free Connections')
        ->assertSeeText('Free includes 2 Connections. Go Pro for as many as you need, or delete one you no longer use.')
        ->assertDontSee(route('connections.add-custom'))
        ->assertDontSeeHtml('wire:click="startConnecting(\'github\')"');
});

it('refuses another Connection at the limit without sending the token anywhere', function (): void {
    config(['nexus.plans.free.connections' => 1]);
    $server = FakeMcpServer::at(GITHUB_MCP_URL);

    $component = Livewire::test('pages::connections.index')->call('startConnecting', 'github');
    Connection::factory()->for($this->user)->create();

    $component->set('token', 'github_pat_good')
        ->call('connect')
        ->assertHasErrors(['limit' => 'Free includes 1 Connection. Go Pro for more, or delete it if you no longer use it.']);

    expect($this->user->connections()->count())->toBe(1)
        ->and($server->requests())->toBeEmpty();
});
