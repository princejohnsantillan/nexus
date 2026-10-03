<?php

declare(strict_types=1);

use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ConnectionOAuthFlow;
use Tests\Support\FakeAuthorizationServer;
use Tests\Support\FakeMcpServer;

beforeEach(function (): void {
    config(['app.url' => 'https://nexus.test']);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('connects Notion in one click: the Connection is saved and the user sent to Notion\'s consent screen, then back to its tools', function (): void {
    $authorizationServer = FakeAuthorizationServer::at('https://mcp.notion.com')->acceptingMetadataDocuments();
    FakeMcpServer::at('https://mcp.notion.com/mcp')->requireOAuth($authorizationServer, scopesSupported: ['default'])->withTools([['name' => 'notion-search']]);

    $component = Livewire::test('pages::connections.index')
        ->call('startConnecting', 'notion')
        ->assertSet('method', 'oauth')
        ->assertDontSeeText('Your own token')
        ->assertSeeText('Nexus sends you to Notion to approve access, then brings you back here and loads the tools.')
        ->assertSeeText('Continue to Notion')
        ->set('description', 'work workspace')
        ->call('connect')
        ->assertHasNoErrors();

    $connection = $this->user->connections()->sole();
    $component->assertRedirect(route('connections.connect', $connection));

    expect($connection->only(['connector_key', 'name', 'handle', 'description', 'url', 'auth_type', 'status', 'settings']))->toBe([
        'connector_key' => 'notion',
        'name' => 'Notion',
        'handle' => 'notion',
        'description' => 'work workspace',
        'url' => 'https://mcp.notion.com/mcp',
        'auth_type' => ConnectionAuthType::OAuth,
        'status' => ConnectionStatus::NeedsAuth,
        'settings' => null,
    ]);

    $consent = ConnectionOAuthFlow::start($this, $connection);

    expect($consent)->toStartWith('https://mcp.notion.com/authorize?');

    $this->get($authorizationServer->approve($consent))
        ->assertRedirect(route('connections.show', $connection))
        ->assertSessionHas('toast.text', 'Signed in. Nexus loaded 1 tool.');

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Connected);
});

it('doesn\'t let Notion sign in with a token it doesn\'t take', function (): void {
    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'notion')
        ->set('method', 'token')
        ->set('token', 'secret_123')
        ->call('connect')
        ->assertHasErrors(['method' => 'Signing in this way isn\'t available.']);

    expect(Connection::query()->count())->toBe(0);
});

it('offers Sign in with GitHub, chosen for the user, when the deployment has a GitHub app', function (): void {
    config(['nexus.connectors.github' => ['client_id' => 'deployment-app', 'client_secret' => 'deployment-secret']]);

    $component = Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->assertSet('method', 'oauth')
        ->assertSeeText('Sign in with GitHub')
        ->assertSeeText('Your own token')
        ->assertDontSeeText('Client secret')
        ->call('connect')
        ->assertHasNoErrors();

    $connection = $this->user->connections()->sole();
    $component->assertRedirect(route('connections.connect', $connection));

    expect($connection->usesOAuth())->toBeTrue()
        ->and($connection->oauthClientId())->toBeNull()
        ->and($connection->secrets->all())->toBe([]);
});

it('signs GitHub in through the user\'s own OAuth app when the deployment has none, showing the callback URL to register', function (): void {
    $component = Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->assertSet('method', 'token')
        ->set('method', 'oauth')
        ->assertSeeText('GitHub only accepts OAuth apps registered in its developer settings.')
        ->assertSee('https://github.com/settings/applications/new')
        ->assertSeeHtml('value="https://nexus.test/oauth/callback"')
        ->assertSeeText('Continue to GitHub')
        ->call('connect')
        ->assertHasErrors(['clientId' => 'required', 'clientSecret' => 'required'])
        ->set('clientId', ' Iv1.my-app ')
        ->set('clientSecret', 'my-secret')
        ->call('connect')
        ->assertHasNoErrors();

    $connection = $this->user->connections()->sole();
    $component->assertRedirect(route('connections.connect', $connection));

    expect($connection->oauthClientId())->toBe('Iv1.my-app')
        ->and($connection->secrets->get('oauth_client_secret'))->toBe('my-secret')
        ->and(DB::table('connections')->value('secrets'))->not->toContain('my-secret');

    FakeMcpServer::at('https://api.githubcopilot.com/mcp/')->requireOAuth(FakeAuthorizationServer::at('https://github.com/login/oauth')->withoutRegistration());

    $consent = ConnectionOAuthFlow::start($this, $connection);

    expect($consent)->toStartWith('https://github.com/login/oauth/authorize?')
        ->toContain('client_id=Iv1.my-app')
        ->toContain('prompt=select_account');
});

it('refuses a client ID with a space or a client secret with a line break', function (): void {
    Livewire::test('pages::connections.index')
        ->call('startConnecting', 'github')
        ->set('method', 'oauth')
        ->set('clientId', 'my app')
        ->set('clientSecret', "my-secret\r\nX-Injected: yes")
        ->call('connect')
        ->assertHasErrors([
            'clientId' => 'The client ID can\'t contain spaces or control characters.',
            'clientSecret' => 'The client secret can\'t contain line breaks or other control characters.',
        ]);
});

it('adds a custom OAuth server, optionally with the user\'s own app, and sends the user to sign in without loading its tools first', function (?string $clientId, ?string $clientSecret): void {
    $server = FakeMcpServer::at()->requireOAuth();

    $component = Livewire::test('pages::connections.add-custom')
        ->set('name', 'Acme')
        ->set('handle', 'acme')
        ->set('url', FakeMcpServer::DEFAULT_URL)
        ->set('authType', 'oauth')
        ->assertSeeHtml('value="https://nexus.test/oauth/callback"')
        ->assertSeeText('Save and sign in')
        ->set('clientId', $clientId ?? '')
        ->set('clientSecret', $clientSecret ?? '')
        ->call('save')
        ->assertHasNoErrors();

    $connection = $this->user->connections()->sole();
    $component->assertRedirect(route('connections.connect', $connection));

    expect($connection->auth_type)->toBe(ConnectionAuthType::OAuth)
        ->and($connection->status)->toBe(ConnectionStatus::NeedsAuth)
        ->and($connection->oauthClientId())->toBe($clientId)
        ->and($connection->secrets->get('oauth_client_secret'))->toBe($clientSecret)
        ->and($server->requests())->toBe([]);
})->with([
    'registered automatically' => [null, null],
    'with the user\'s own app' => ['my-app', 'my-secret'],
    'with a public app' => ['my-app', null],
]);

it('asks a Connection that needs sign-in to reconnect', function (): void {
    $connection = Connection::factory()->for($this->user)->fromConnector('notion')->oauth()->create(['last_error' => 'The sign-in expired and the server didn\'t renew it. Reconnect to sign in again.']);

    $this->get(route('connections.show', $connection))
        ->assertOk()
        ->assertSeeText('Sign in to use this Connection')
        ->assertSeeText('The sign-in expired and the server didn\'t renew it. Reconnect to sign in again.')
        ->assertSee('href="'.route('connections.connect', $connection).'"', escape: false)
        ->assertSeeText('Reconnect')
        ->assertSeeTextInOrder(['Sign-in', 'Sign in with Notion'])
        ->assertDontSeeText('Server and sign-in');
});

it('doesn\'t offer to reconnect a Connection that is signed in, or one that signs in with a header', function (Closure $connection): void {
    $this->get(route('connections.show', $connection($this->user)))
        ->assertOk()
        ->assertDontSeeText('Reconnect');
})->with([
    'signed in' => [fn (User $user): Connection => Connection::factory()->for($user)->oauth()->connected()->create()],
    'a refused header' => [fn (User $user): Connection => Connection::factory()->for($user)->withHeader()->create(['status' => ConnectionStatus::NeedsAuth, 'last_error' => 'The server refused the credentials Nexus sent (HTTP 401).'])],
]);

it('names the user\'s own OAuth app among the sign-in details', function (): void {
    $connection = Connection::factory()->for($this->user)->fromConnector('github')->oauth('Iv1.my-app', 'my-secret')->create();

    $this->get(route('connections.show', $connection))
        ->assertSeeTextInOrder(['Sign-in', 'Your own OAuth app', 'client ID', 'Iv1.my-app'])
        ->assertDontSee('my-secret');
});

it('switches a custom server to OAuth and sends the user to sign in, clearing its header', function (): void {
    $connection = Connection::factory()->for($this->user)->withHeader('Bearer sk-live-123')->connected()->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->set('authType', 'oauth')
        ->assertSeeText('Save and sign in')
        ->set('clientId', 'my-app')
        ->call('saveServer')
        ->assertHasNoErrors()
        ->assertRedirect(route('connections.connect', $connection));

    expect($connection->refresh()->auth_type)->toBe(ConnectionAuthType::OAuth)
        ->and($connection->status)->toBe(ConnectionStatus::NeedsAuth)
        ->and($connection->settings)->toBe(['oauth_client_id' => 'my-app'])
        ->and($connection->secrets->all())->toBe([]);
});

it('clears a custom server\'s tokens and registered client when its URL changes', function (): void {
    $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth()->create();
    ConnectionOAuthFlow::signIn($this, $connection, $server->authorizationServer());

    Livewire::test('pages::connections.show', ['connection' => $connection->refresh()])
        ->set('url', 'https://other.example.com/mcp')
        ->call('saveServer')
        ->assertRedirect(route('connections.connect', $connection));

    $connection->refresh();

    expect($connection->status)->toBe(ConnectionStatus::NeedsAuth)
        ->and($connection->settings)->toBeNull()
        ->and($connection->secrets->all())->toBe([])
        ->and($connection->tools()->count())->toBe(0);
});

it('ends the sign-in when the user\'s own app changes, and keeps it when only the secret does', function (): void {
    $authorizationServer = FakeAuthorizationServer::at()->acceptingClient('my-app', 'my-secret');
    $server = FakeMcpServer::at()->requireOAuth($authorizationServer)->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth('my-app', 'my-secret')->create();
    ConnectionOAuthFlow::signIn($this, $connection, $authorizationServer);
    $authorizationServer->acceptingClient('my-app', 'my-new-secret');

    Livewire::test('pages::connections.show', ['connection' => $connection->refresh()])
        ->assertSet('clientId', 'my-app')
        ->assertSeeText('Stored encrypted. Leave blank to keep the current secret.')
        ->set('clientSecret', 'my-new-secret')
        ->call('saveServer')
        ->assertNoRedirect()
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === 'Saved. Nexus loaded 1 tool.');

    expect($connection->refresh()->hasAccessToken())->toBeTrue()
        ->and($connection->secrets->get('oauth_client_secret'))->toBe('my-new-secret');

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->set('clientId', 'another-app')
        ->call('saveServer')
        ->assertRedirect(route('connections.connect', $connection));

    expect($connection->refresh()->hasAccessToken())->toBeFalse()
        ->and($connection->oauthClientId())->toBe('another-app')
        ->and($connection->secrets->get('oauth_client_secret'))->toBeNull()
        ->and($connection->setting('client_source'))->toBeNull()
        ->and($server->requests())->not->toBeEmpty();
});

it('clears every OAuth token and client when a custom server stops using OAuth', function (): void {
    $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth()->create();
    ConnectionOAuthFlow::signIn($this, $connection, $server->authorizationServer());
    FakeMcpServer::at('https://open.example.com/mcp')->withTools([['name' => 'search']]);

    Livewire::test('pages::connections.show', ['connection' => $connection->refresh()])
        ->set('url', 'https://open.example.com/mcp')
        ->set('authType', 'none')
        ->call('saveServer')
        ->assertNoRedirect();

    expect($connection->refresh()->auth_type)->toBe(ConnectionAuthType::None)
        ->and($connection->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->settings)->toBeNull()
        ->and($connection->secrets->all())->toBe([]);
});

it('says why the tools didn\'t load on a Connection that was never signed in', function (): void {
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    Livewire::test('pages::connections.show', ['connection' => $connection])
        ->call('refreshTools')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === 'Nexus couldn\'t load the tools: Nexus isn\'t signed in to this server. Reconnect to sign in.');
});

it('sends a Connection that doesn\'t sign in with OAuth to its own page from the reconnect link', function (): void {
    $connection = Connection::factory()->for($this->user)->withHeader()->create(['status' => ConnectionStatus::NeedsAuth]);

    $this->get(route('connections.connect', $connection))
        ->assertRedirect(route('connections.show', $connection))
        ->assertSessionHas('toast', ['variant' => 'warning', 'text' => 'This Connection doesn\'t sign in with OAuth. Update its credentials here.']);
});

it('keeps the reconnect link to the Connection\'s owner', function (): void {
    $connection = Connection::factory()->oauth()->create();

    $this->get(route('connections.connect', $connection))->assertNotFound();

    auth()->logout();

    $this->get(route('connections.connect', $connection))->assertRedirect(route('auth.sign-in'));
});

it('publishes Nexus\'s Client ID Metadata Document to anyone', function (): void {
    config(['app.url' => 'https://nexus.example.com']);
    auth()->logout();

    $response = $this->get('/oauth/client-metadata.json')->assertOk();

    expect($response->json())->toBe([
        'client_id' => 'https://nexus.example.com/oauth/client-metadata.json',
        'client_name' => 'Nexus',
        'client_uri' => 'https://nexus.example.com/',
        'redirect_uris' => ['https://nexus.example.com/oauth/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ])->and($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=3600');
});
