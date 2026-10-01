<?php

declare(strict_types=1);

use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Uri;
use Tests\Support\ConnectionOAuthFlow;
use Tests\Support\FakeAuthorizationServer;
use Tests\Support\FakeMcpServer;

const CALLBACK_URL = 'https://nexus.test/oauth/callback';

/**
 * The query of a URL.
 *
 * @return array<string, mixed>
 */
function queryOf(string $url): array
{
    return Uri::of($url)->query()->all();
}

/**
 * Collect every message logged from now on.
 *
 * @return ArrayObject<int, string>
 */
function logged(): ArrayObject
{
    $messages = new ArrayObject;

    Event::listen(function (MessageLogged $logged) use ($messages): void {
        $messages[] = $logged->message;
    });

    return $messages;
}

beforeEach(function (): void {
    config(['app.url' => 'https://nexus.test']);
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('sends the user to the server\'s sign-in page with PKCE, a random state, the resource and the scopes the challenge asks for', function (): void {
    $server = FakeMcpServer::at()->requireOAuth(scope: 'read write');
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    $location = ConnectionOAuthFlow::start($this, $connection);
    $query = queryOf($location);

    expect($location)->toStartWith('https://auth.example.com/authorize?')
        ->and($query)->toMatchArray([
            'response_type' => 'code',
            'client_id' => 'registered-client-1',
            'redirect_uri' => CALLBACK_URL,
            'code_challenge_method' => 'S256',
            'scope' => 'read write',
            'resource' => FakeMcpServer::DEFAULT_URL,
        ])
        ->and($query['code_challenge'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['state'])->toHaveLength(40)
        ->and($query)->not->toHaveKey('prompt')
        ->and($server->authorizationServer()->registrations())->toBe([[
            'client_name' => 'Nexus',
            'client_uri' => 'https://nexus.test/',
            'redirect_uris' => [CALLBACK_URL],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'client_secret_post',
            'scope' => 'read write',
        ]]);

    expect($connection->refresh()->settings)->toBe([
        'registered_client_id' => 'registered-client-1',
        'registered_issuer' => 'https://auth.example.com',
        'registered_redirect_uri' => CALLBACK_URL,
        'registered_auth_method' => 'client_secret_post',
    ])->and($connection->secrets->get('registered_client_secret'))->toBe('registered-secret-1')
        ->and(DB::table('connections')->value('secrets'))->not->toContain('registered-secret-1');
});

it('finishes the sign-in: exchanges the code with the PKCE verifier, stores the tokens encrypted and loads the tools', function (): void {
    $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search', 'annotations' => ['readOnlyHint' => true]]]);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    ConnectionOAuthFlow::signIn($this, $connection, $server->authorizationServer())
        ->assertRedirect(route('connections.show', $connection))
        ->assertSessionHas('toast', ['variant' => 'success', 'text' => 'Signed in. Nexus loaded 1 tool.']);

    $connection->refresh();

    expect($connection->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->last_error)->toBeNull()
        ->and($connection->tools()->pluck('name')->all())->toBe(['search'])
        ->and($connection->secrets->get('access_token'))->toBe('access-token-1')
        ->and($connection->secrets->get('refresh_token'))->toBe('refresh-token-1')
        ->and($connection->secrets->get('expires_at'))->toBe(now()->getTimestamp() + 3600)
        ->and(DB::table('connections')->value('secrets'))->not->toContain('access-token-1')
        ->and(DB::table('connections')->value('secrets'))->not->toContain('refresh-token-1')
        ->and($connection->settings)->toMatchArray([
            'client_source' => 'registered',
            'client_id' => 'registered-client-1',
            'token_auth_method' => 'client_secret_post',
            'token_endpoint' => 'https://auth.example.com/token',
            'issuer' => 'https://auth.example.com',
            'resource' => FakeMcpServer::DEFAULT_URL,
        ])
        ->and($server->requests()[array_key_last($server->requests())]->header('Authorization'))->toBe(['Bearer access-token-1']);

    expect($server->authorizationServer()->tokenRequests('authorization_code')[0])->toMatchArray([
        'redirect_uri' => CALLBACK_URL,
        'resource' => FakeMcpServer::DEFAULT_URL,
        'client_id' => 'registered-client-1',
        'client_secret' => 'registered-secret-1',
    ]);
});

/**
 * Start signing the Connection in and return the toast it ends with, for a start that fails.
 */
function failedStart(Connection $connection): string
{
    test()->get(route('connections.connect', $connection))->assertRedirect(route('connections.show', $connection));

    expect(session('toast.variant'))->toBe('danger');

    return (string) session('toast.text');
}

it('finds the protected-resource metadata at its well-known URL when the challenge doesn\'t name it', function (string $metadataAt): void {
    FakeMcpServer::at()->requireOAuth(namedInChallenge: false, metadataAt: $metadataAt);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(ConnectionOAuthFlow::start($this, $connection))->toStartWith('https://auth.example.com/authorize?');
})->with(['under the server\'s path' => 'path', 'at the root' => 'root']);

it('treats a server without protected-resource metadata as its own authorization server', function (): void {
    $authorizationServer = FakeAuthorizationServer::at('https://mcp.example.com');
    FakeMcpServer::at()->requireOAuth($authorizationServer, namedInChallenge: false, metadataAt: 'none');
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(ConnectionOAuthFlow::start($this, $connection))->toStartWith('https://mcp.example.com/authorize?');
});

it('reads an authorization server\'s OpenID configuration when it publishes no OAuth metadata', function (string $kind): void {
    $authorizationServer = FakeAuthorizationServer::at('https://auth.example.com/tenant')->publishingMetadataAt($kind);
    $server = FakeMcpServer::at()->requireOAuth($authorizationServer)->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    ConnectionOAuthFlow::signIn($this, $connection, $authorizationServer)->assertRedirect(route('connections.show', $connection));

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->setting('token_endpoint'))->toBe('https://auth.example.com/tenant/token')
        ->and($server->authorizationServer())->toBe($authorizationServer);
})->with(['path inserted' => 'openid', 'path appended' => 'openid-suffix']);

it('finds an authorization server listed with a trailing slash its metadata doesn\'t have', function (): void {
    FakeMcpServer::at()->requireOAuth(authorizationServers: ['https://auth.example.com/']);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(ConnectionOAuthFlow::start($this, $connection))->toStartWith('https://auth.example.com/authorize?');
});

it('accepts a resource named by a shorter URL on the same origin, and names it that way to the authorization server', function (string $resource): void {
    $server = FakeMcpServer::at()->requireOAuth(resource: $resource)->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(queryOf(ConnectionOAuthFlow::start($this, $connection))['resource'])->toBe($resource);

    ConnectionOAuthFlow::signIn($this, $connection, $server->authorizationServer());

    expect($server->authorizationServer()->tokenRequests('authorization_code')[0]['resource'])->toBe($resource)
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->setting('resource'))->toBe($resource);
})->with(['the origin' => 'https://mcp.example.com', 'with a trailing slash' => 'https://mcp.example.com/mcp/']);

it('refuses protected-resource metadata for another server', function (string $resource): void {
    $server = FakeMcpServer::at()->requireOAuth(resource: $resource);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(failedStart($connection))->toBe('Nexus couldn\'t start signing in. The server\'s sign-in settings are for a different server, so Nexus won\'t use them.')
        ->and($server->authorizationServer()->registrations())->toBe([]);
})->with(['another origin' => 'https://evil.example.com/mcp', 'another path' => 'https://mcp.example.com/other', 'a longer path' => 'https://mcp.example.com/mcp/admin']);

it('refuses an authorization server whose metadata names another issuer', function (): void {
    FakeMcpServer::at()->requireOAuth(FakeAuthorizationServer::at()->namedInMetadataAs('https://evil.example.com'));
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(failedStart($connection))->toContain('The authorization server\'s settings name a different issuer');
});

it('refuses an authorization server that doesn\'t support PKCE with S256', function (array $methods): void {
    FakeMcpServer::at()->requireOAuth(FakeAuthorizationServer::at()->withMetadata(['code_challenge_methods_supported' => $methods]));
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(failedStart($connection))->toContain('doesn\'t support PKCE with S256, which Nexus requires.');
})->with(['plain only' => [['plain']], 'none listed' => [[]]]);

it('refuses a sign-in page that isn\'t on HTTPS', function (): void {
    FakeMcpServer::at()->requireOAuth(FakeAuthorizationServer::at()->withMetadata(['authorization_endpoint' => 'http://auth.example.com/authorize']));
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(failedStart($connection))->toContain('doesn\'t say where to sign in over HTTPS.');
});

it('says when a server lets Nexus in without signing in', function (): void {
    FakeMcpServer::at()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(failedStart($connection))->toBe('Nexus couldn\'t start signing in. This server lets Nexus in without signing in, so it doesn\'t need OAuth. Choose No auth instead.');
});

it('says when the server can\'t be asked how to sign in', function (): void {
    FakeMcpServer::at()->respondTo('server/discover', FakeMcpServer::unreachable());
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(failedStart($connection))->toBe('Nexus couldn\'t start signing in. Nexus couldn\'t ask the server how to sign in. Nexus could not connect to the server.');
});

it('signs in with the user\'s own OAuth app instead of registering', function (): void {
    $authorizationServer = FakeAuthorizationServer::at()->acceptingClient('my-app', 'my-secret');
    $server = FakeMcpServer::at()->requireOAuth($authorizationServer)->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth('my-app', 'my-secret')->create();

    expect(queryOf(ConnectionOAuthFlow::start($this, $connection))['client_id'])->toBe('my-app');

    ConnectionOAuthFlow::signIn($this, $connection, $authorizationServer);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->settings)->toMatchArray(['client_source' => 'own_app', 'client_id' => 'my-app'])
        ->and($authorizationServer->registrations())->toBe([])
        ->and($authorizationServer->tokenRequests('authorization_code')[0])->toMatchArray(['client_id' => 'my-app', 'client_secret' => 'my-secret'])
        ->and($server->requests())->not->toBeEmpty();
});

it('authenticates the client with HTTP Basic when that is all the server allows', function (): void {
    $authorizationServer = FakeAuthorizationServer::at()->acceptingClient('my-app', 'my-secret')->withMetadata(['token_endpoint_auth_methods_supported' => ['client_secret_basic']]);
    FakeMcpServer::at()->requireOAuth($authorizationServer);
    $connection = Connection::factory()->for($this->user)->oauth('my-app', 'my-secret')->create();

    ConnectionOAuthFlow::signIn($this, $connection, $authorizationServer);

    expect($authorizationServer->tokenRequests('authorization_code')[0])->toMatchArray(['basic_auth' => true, 'client_id' => 'my-app', 'client_secret' => 'my-secret'])
        ->and($connection->refresh()->setting('token_auth_method'))->toBe('client_secret_basic');
});

it('signs GitHub in through the deployment\'s OAuth app, asking for the connector\'s scopes and an account chooser', function (): void {
    config(['nexus.connectors.github' => ['client_id' => 'deployment-app', 'client_secret' => 'deployment-secret']]);
    $authorizationServer = FakeAuthorizationServer::at('https://github.com/login/oauth')
        ->withoutRegistration()
        ->acceptingClient('deployment-app', 'deployment-secret')
        ->withMetadata(['token_endpoint_auth_methods_supported' => []]);
    FakeMcpServer::at('https://api.githubcopilot.com/mcp/')->requireOAuth($authorizationServer, scopesSupported: ['repo', 'gist'])->withTools([['name' => 'get_me']]);
    $connection = Connection::factory()->for($this->user)->fromConnector('github')->oauth()->create();

    $query = queryOf(ConnectionOAuthFlow::start($this, $connection));

    expect($query)->toMatchArray([
        'client_id' => 'deployment-app',
        'scope' => 'repo read:org read:user user:email',
        'prompt' => 'select_account',
        'resource' => 'https://api.githubcopilot.com/mcp/',
    ]);

    ConnectionOAuthFlow::signIn($this, $connection, $authorizationServer);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->settings)->toMatchArray(['client_source' => 'deployment_app', 'client_id' => 'deployment-app', 'token_auth_method' => 'client_secret_post'])
        ->and($connection->secrets->all())->not->toHaveKey('oauth_client_secret')
        ->and(json_encode($connection->secrets->all()))->not->toContain('deployment-secret');
});

it('asks for every scope the server lists when its challenge names none', function (): void {
    FakeMcpServer::at()->requireOAuth(scopesSupported: ['read', 'write']);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(queryOf(ConnectionOAuthFlow::start($this, $connection))['scope'])->toBe('read write');
});

it('names Nexus by its Client ID Metadata Document where the server accepts one and can fetch it', function (): void {
    config(['app.url' => 'https://nexus.example.com']);
    $authorizationServer = FakeAuthorizationServer::at()->acceptingMetadataDocuments();
    FakeMcpServer::at()->requireOAuth($authorizationServer)->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(queryOf(ConnectionOAuthFlow::start($this, $connection)))->toMatchArray([
        'client_id' => 'https://nexus.example.com/oauth/client-metadata.json',
        'redirect_uri' => 'https://nexus.example.com/oauth/callback',
    ]);

    ConnectionOAuthFlow::signIn($this, $connection, $authorizationServer);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->settings)->toMatchArray(['client_source' => 'metadata_document', 'token_auth_method' => 'none'])
        ->and($authorizationServer->registrations())->toBe([])
        ->and($authorizationServer->tokenRequests('authorization_code')[0])->not->toHaveKey('client_secret');
});

it('registers instead of using its metadata document when servers can\'t fetch it', function (string $appUrl): void {
    config(['app.url' => $appUrl]);
    $authorizationServer = FakeAuthorizationServer::at()->acceptingMetadataDocuments();
    FakeMcpServer::at()->requireOAuth($authorizationServer);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(queryOf(ConnectionOAuthFlow::start($this, $connection))['client_id'])->toBe('registered-client-1');
})->with(['a local site' => 'https://nexus.test', 'plain HTTP' => 'http://nexus.example.com', 'localhost' => 'https://localhost', 'a private address' => 'https://10.0.0.5']);

it('registers once, and signs in again with the client the server registered', function (): void {
    $server = FakeMcpServer::at()->requireOAuth();
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    ConnectionOAuthFlow::start($this, $connection);
    $query = queryOf(ConnectionOAuthFlow::start($this, $connection));

    expect($query['client_id'])->toBe('registered-client-1')
        ->and($server->authorizationServer()->registrations())->toHaveCount(1);
});

it('registers a public client when the server allows nothing else', function (): void {
    $authorizationServer = FakeAuthorizationServer::at()->registeringPublicClients()->withMetadata(['token_endpoint_auth_methods_supported' => ['none']]);
    FakeMcpServer::at()->requireOAuth($authorizationServer)->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    ConnectionOAuthFlow::signIn($this, $connection, $authorizationServer);

    expect($authorizationServer->registrations()[0]['token_endpoint_auth_method'])->toBe('none')
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->setting('token_auth_method'))->toBe('none');
});

it('asks for a client of the user\'s own when the server can\'t register Nexus', function (): void {
    FakeMcpServer::at()->requireOAuth(FakeAuthorizationServer::at()->withoutRegistration());
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(failedStart($connection))->toBe('Nexus couldn\'t start signing in. The server doesn\'t let Nexus register itself. Register an OAuth app on it and enter its client ID instead.');
});

it('says when the server refuses to register Nexus, without repeating it', function (): void {
    $logged = logged();
    FakeMcpServer::at()->requireOAuth(FakeAuthorizationServer::at()->respondTo('register', fn () => Http::response(['error' => 'invalid_client_metadata', 'error_description' => 'Secret server details.'], 400)));
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    expect(failedStart($connection))->toBe('Nexus couldn\'t start signing in. The server refused to register Nexus as a client (HTTP 400).')
        ->and($connection->refresh()->settings)->toBeNull()
        ->and($logged->getArrayCopy())->toBe([]);
});

it('forgets a sign-in once it is finished, so its callback can\'t be used again', function (): void {
    $server = FakeMcpServer::at()->requireOAuth();
    $connection = Connection::factory()->for($this->user)->oauth()->create();
    $callback = $server->authorizationServer()->approve(ConnectionOAuthFlow::start($this, $connection));

    $this->get($callback)->assertRedirect(route('connections.show', $connection));
    $this->get($callback)
        ->assertRedirect(route('connections.index'))
        ->assertSessionHas('toast', ['variant' => 'danger', 'text' => 'This sign-in has expired or was already used. Start it again from the Connection.']);

    expect($server->authorizationServer()->tokenRequests())->toHaveCount(1);
});

it('ignores a callback with a state it didn\'t send', function (?string $state): void {
    $server = FakeMcpServer::at()->requireOAuth();
    $connection = Connection::factory()->for($this->user)->oauth()->create();
    ConnectionOAuthFlow::start($this, $connection);

    $this->get(route('oauth.callback', array_filter(['code' => 'code-forged', 'state' => $state])))
        ->assertRedirect(route('connections.index'))
        ->assertSessionHas('toast.text', 'This sign-in has expired or was already used. Start it again from the Connection.');

    expect($server->authorizationServer()->tokenRequests())->toBe([])
        ->and($connection->refresh()->hasAccessToken())->toBeFalse();
})->with(['forged' => 'forged-state', 'missing' => null]);

it('finishes a sign-in only for the user who started it', function (): void {
    $server = FakeMcpServer::at()->requireOAuth();
    $connection = Connection::factory()->for($this->user)->oauth()->create();
    $callback = $server->authorizationServer()->approve(ConnectionOAuthFlow::start($this, $connection));

    $this->actingAs(User::factory()->create())
        ->get($callback)
        ->assertRedirect(route('connections.index'))
        ->assertSessionHas('toast.text', 'This sign-in has expired or was already used. Start it again from the Connection.');

    expect($server->authorizationServer()->tokenRequests())->toBe([])
        ->and($connection->refresh()->hasAccessToken())->toBeFalse();
});

it('keeps the five newest sign-ins in progress', function (): void {
    $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    $sent = array_map(fn (): string => ConnectionOAuthFlow::start($this, $connection), range(1, 6));

    $this->get($server->authorizationServer()->approve($sent[0]))->assertSessionHas('toast.text', 'This sign-in has expired or was already used. Start it again from the Connection.');
    $this->get($server->authorizationServer()->approve($sent[1]))->assertSessionHas('toast.variant', 'success');
});

it('runs two sign-ins to the same server at once, each for its own Connection', function (): void {
    $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search']]);
    $authorizationServer = $server->authorizationServer();
    $work = Connection::factory()->for($this->user)->oauth()->create();
    $personal = Connection::factory()->for($this->user)->oauth()->create();

    $workCallback = $authorizationServer->approve(ConnectionOAuthFlow::start($this, $work));
    $personalCallback = $authorizationServer->approve(ConnectionOAuthFlow::start($this, $personal));

    $this->get($personalCallback)->assertRedirect(route('connections.show', $personal));
    $this->get($workCallback)->assertRedirect(route('connections.show', $work));

    expect($personal->refresh()->secrets->get('access_token'))->toBe('access-token-1')
        ->and($work->refresh()->secrets->get('access_token'))->toBe('access-token-2');
});

it('says so, and keeps the Connection as it was, when the user doesn\'t approve Nexus', function (string $error, string $toast): void {
    $server = FakeMcpServer::at()->requireOAuth();
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    $this->get($server->authorizationServer()->deny(ConnectionOAuthFlow::start($this, $connection), $error))
        ->assertRedirect(route('connections.show', $connection))
        ->assertSessionHas('toast', ['variant' => 'danger', 'text' => $toast]);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::NeedsAuth)
        ->and($server->authorizationServer()->tokenRequests())->toBe([]);
})->with([
    'refused' => ['access_denied', 'You didn\'t approve Nexus, so it isn\'t signed in.'],
    'a standard error' => ['invalid_scope', 'The server\'s sign-in page reported an error: invalid_scope.'],
    'anything else' => ['<script>alert(1)</script>', 'The server\'s sign-in page reported an error.'],
]);

it('reports a refusal from a server that names itself on approvals but not on refusals, as Linear does', function (?string $issuer): void {
    $authorizationServer = FakeAuthorizationServer::at()->namingItselfOnReturn();
    FakeMcpServer::at()->requireOAuth($authorizationServer);
    $connection = Connection::factory()->for($this->user)->oauth()->create();
    $refusal = Uri::of($authorizationServer->deny(ConnectionOAuthFlow::start($this, $connection)))->withoutQuery(['iss']);

    $this->get(($issuer === null ? $refusal : $refusal->withQuery(['iss' => $issuer]))->value())
        ->assertRedirect(route('connections.show', $connection))
        ->assertSessionHas('toast', ['variant' => 'danger', 'text' => 'You didn\'t approve Nexus, so it isn\'t signed in.']);

    expect($authorizationServer->tokenRequests())->toBe([])
        ->and($connection->refresh()->hasAccessToken())->toBeFalse();
})->with(['without iss' => null, 'with another iss' => 'https://evil.example.com']);

it('says so, without the server\'s text or a log entry, when the server refuses the code', function (): void {
    $logged = logged();
    $server = FakeMcpServer::at()->requireOAuth();
    $connection = Connection::factory()->for($this->user)->oauth()->create();
    $callback = $server->authorizationServer()->approve(ConnectionOAuthFlow::start($this, $connection));

    $this->get(Uri::of($callback)->withQuery(['code' => 'code-tampered'])->value())
        ->assertRedirect(route('connections.show', $connection))
        ->assertSessionHas('toast', ['variant' => 'danger', 'text' => 'The server refused to finish the sign-in (HTTP 400): invalid_grant.']);

    expect($connection->refresh()->hasAccessToken())->toBeFalse()
        ->and($connection->status)->toBe(ConnectionStatus::NeedsAuth)
        ->and($logged->getArrayCopy())->toBe([]);
});

it('says so when the authorization server can\'t be reached to finish the sign-in', function (): void {
    $server = FakeMcpServer::at()->requireOAuth(FakeAuthorizationServer::at()->respondTo('token', fn ($request) => Http::failedConnection()($request)));
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    ConnectionOAuthFlow::signIn($this, $connection, $server->authorizationServer())
        ->assertSessionHas('toast', ['variant' => 'danger', 'text' => 'Nexus couldn\'t reach the server\'s sign-in service.']);
});

it('checks the user came back from the authorization server Nexus sent them to', function (): void {
    $authorizationServer = FakeAuthorizationServer::at()->namingItselfOnReturn();
    $server = FakeMcpServer::at()->requireOAuth($authorizationServer)->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth()->create();
    $mixedUp = Uri::of($authorizationServer->approve(ConnectionOAuthFlow::start($this, $connection)))->withQuery(['iss' => 'https://evil.example.com']);
    $missing = Uri::of($authorizationServer->approve(ConnectionOAuthFlow::start($this, $connection)))->withoutQuery(['iss']);

    foreach ([$mixedUp, $missing] as $callback) {
        $this->get($callback->value())->assertSessionHas('toast.text', 'The sign-in came back from a different server than Nexus sent you to, so Nexus ignored it.');
    }

    expect($authorizationServer->tokenRequests())->toBe([]);

    ConnectionOAuthFlow::signIn($this, $connection, $authorizationServer)->assertSessionHas('toast.variant', 'success');
});

it('drops a sign-in whose Connection moved to another server meanwhile', function (): void {
    $server = FakeMcpServer::at()->requireOAuth();
    $connection = Connection::factory()->for($this->user)->oauth()->create();
    $callback = $server->authorizationServer()->approve(ConnectionOAuthFlow::start($this, $connection));

    $connection->update(['url' => 'https://other.example.com/mcp']);

    $this->get($callback)
        ->assertRedirect(route('connections.show', $connection))
        ->assertSessionHas('toast.text', 'The Connection\'s server or sign-in changed while you were signing in. Start again.');

    expect($connection->refresh()->hasAccessToken())->toBeFalse()
        ->and($server->authorizationServer()->tokenRequests())->toBe([]);
});

it('signs in again over an existing sign-in, keeping the Connection\'s tools', function (): void {
    $server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search']]);
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    ConnectionOAuthFlow::signIn($this, $connection, $server->authorizationServer());
    $signedInAt = $connection->refresh()->setting('signed_in_at');
    $this->travel(5)->seconds();
    ConnectionOAuthFlow::signIn($this, $connection, $server->authorizationServer());

    expect($connection->refresh()->secrets->get('access_token'))->toBe('access-token-2')
        ->and($connection->secrets->get('refresh_token'))->toBe('refresh-token-2')
        ->and($connection->setting('signed_in_at'))->not->toBe($signedInAt)
        ->and($connection->tools()->pluck('name')->all())->toBe(['search']);
});

it('warns when the sign-in worked but the tools didn\'t load', function (): void {
    $server = FakeMcpServer::at()->requireOAuth()->respondTo('tools/list', FakeMcpServer::httpStatus(503));
    $connection = Connection::factory()->for($this->user)->oauth()->create();

    ConnectionOAuthFlow::signIn($this, $connection, $server->authorizationServer())
        ->assertSessionHas('toast', ['variant' => 'warning', 'text' => 'Signed in, but Nexus couldn\'t load its tools. The Connection page says why.']);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Error)
        ->and($connection->hasAccessToken())->toBeTrue();
});

it('needs the user signed in to Nexus for the callback', function (): void {
    auth()->logout();

    $this->get(route('oauth.callback', ['code' => 'code', 'state' => 'state']))->assertRedirect(route('home'));
});
