<?php

declare(strict_types=1);

use App\Actions\RefreshCatalog;
use App\Actions\UpdateConnectionServer;
use App\ConnectionOAuth\ConnectionTokens;
use App\Downstream\DownstreamClient;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Enums\DownstreamFailure;
use App\Exceptions\DownstreamRequestFailed;
use App\Models\Connection;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\ConnectionOAuthFlow;
use Tests\Support\FakeAuthorizationServer;
use Tests\Support\FakeMcpServer;

/**
 * List the Connection's tools through a session, returning the failure it throws, if any.
 */
function listingFailure(Connection $connection): ?DownstreamRequestFailed
{
    try {
        resolve(DownstreamClient::class)->session($connection)->listTools();
    } catch (DownstreamRequestFailed $failed) {
        return $failed;
    }

    return null;
}

beforeEach(function (): void {
    config(['app.url' => 'https://nexus.test']);
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->server = FakeMcpServer::at()->requireOAuth()->withTools([['name' => 'search']]);
    $this->auth = $this->server->authorizationServer();
    $this->connection = Connection::factory()->for($this->user)->oauth()->create();

    ConnectionOAuthFlow::signIn($this, $this->connection, $this->auth);
    $this->connection->refresh();
});

it('sends the access token with every request while it is good', function (): void {
    $this->travel(30)->minutes();
    $requestsBefore = count($this->server->requests());

    expect(listingFailure($this->connection))->toBeNull()
        ->and($this->auth->tokenRequests('refresh_token'))->toBe([])
        ->and(collect(array_slice($this->server->requests(), $requestsBefore))->map(fn (Request $request): array => $request->header('Authorization'))->unique()->values()->all())
        ->toBe([['Bearer access-token-1']]);
});

it('renews an access token that has expired, or is about to, before using it, and stores the rotated refresh token', function (int $minutes): void {
    $this->travel($minutes)->minutes();

    expect(listingFailure($this->connection))->toBeNull();

    $renewal = $this->auth->tokenRequests('refresh_token');
    $this->connection->refresh();

    expect($renewal)->toHaveCount(1)
        ->and($renewal[0])->toMatchArray([
            'refresh_token' => 'refresh-token-1',
            'resource' => FakeMcpServer::DEFAULT_URL,
            'client_id' => 'registered-client-1',
            'client_secret' => 'registered-secret-1',
        ])
        ->and($this->connection->secrets->get('access_token'))->toBe('access-token-2')
        ->and($this->connection->secrets->get('refresh_token'))->toBe('refresh-token-2')
        ->and($this->connection->secrets->get('expires_at'))->toBe(now()->getTimestamp() + 3600)
        ->and(last($this->server->requests())->header('Authorization'))->toBe(['Bearer access-token-2']);
})->with(['expired' => 61, 'expiring within a minute' => 59]);

it('updates the account when a renewal names it, and keeps it when a renewal doesn\'t', function (): void {
    $this->connection->forceFill(['account_identity' => 'Old Workspace'])->save();
    $this->travel(2)->hours();

    expect(listingFailure($this->connection))->toBeNull()
        ->and($this->connection->refresh()->account_identity)->toBe('Old Workspace');

    $this->auth->withTokenFields(['workspace_name' => 'BetterWorld']);
    $this->travel(2)->hours();

    expect(listingFailure($this->connection))->toBeNull()
        ->and($this->connection->refresh()->account_identity)->toBe('BetterWorld')
        ->and($this->auth->tokenRequests('refresh_token'))->toHaveCount(2);
});

it('keeps the refresh token when the server doesn\'t rotate it', function (): void {
    $this->auth->keepingRefreshTokens();
    $this->travel(2)->hours();

    expect(listingFailure($this->connection))->toBeNull()
        ->and($this->connection->refresh()->secrets->get('access_token'))->toBe('access-token-2')
        ->and($this->connection->secrets->get('refresh_token'))->toBe('refresh-token-1');
});

it('never renews an access token the server said nothing about expiring', function (): void {
    $this->auth->issuingTokensFor(null);
    $connection = Connection::factory()->for($this->user)->oauth()->create();
    ConnectionOAuthFlow::signIn($this, $connection, $this->auth);
    $this->travel(1)->years();

    expect(listingFailure($connection->refresh()))->toBeNull()
        ->and($connection->secrets->get('expires_at'))->toBeNull()
        ->and($this->auth->tokenRequests('refresh_token'))->toBe([]);
});

it('ends the sign-in when the server refuses to renew it, without the server\'s text or a log entry', function (): void {
    $logged = [];
    Event::listen(function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });
    $this->auth->respondTo('token', fn (): PromiseInterface => Http::response(['error' => 'invalid_grant', 'error_description' => 'Secret server details.'], 400));
    $this->travel(2)->hours();
    $requestsBefore = count($this->server->requests());

    $failure = listingFailure($this->connection);
    $this->connection->refresh();

    expect($failure?->failure)->toBe(DownstreamFailure::NeedsSignIn)
        ->and($failure?->getMessage())->toBe('The sign-in expired and the server didn\'t renew it. Reconnect to sign in again.')
        ->and($this->connection->status)->toBe(ConnectionStatus::NeedsAuth)
        ->and($this->connection->last_error)->toBe('The sign-in expired and the server didn\'t renew it. Reconnect to sign in again.')
        ->and($this->connection->hasAccessToken())->toBeFalse()
        ->and($this->connection->secrets->get('refresh_token'))->toBeNull()
        ->and($this->connection->setting('client_source'))->toBeNull()
        ->and($this->connection->setting('registered_client_id'))->toBe('registered-client-1')
        ->and(count($this->server->requests()))->toBe($requestsBefore)
        ->and($logged)->toBe([]);

    expect(listingFailure($this->connection)?->getMessage())->toBe('Nexus isn\'t signed in to this server. Reconnect to sign in.')
        ->and($this->auth->tokenRequests('refresh_token'))->toHaveCount(1);
});

it('ends the sign-in when the access token expired and there is no refresh token', function (): void {
    $this->auth->withoutRefreshTokens();
    $connection = Connection::factory()->for($this->user)->oauth()->create();
    ConnectionOAuthFlow::signIn($this, $connection, $this->auth);
    $this->travel(2)->hours();

    expect(listingFailure($connection->refresh())?->failure)->toBe(DownstreamFailure::NeedsSignIn)
        ->and($connection->refresh()->status)->toBe(ConnectionStatus::NeedsAuth)
        ->and($this->auth->tokenRequests('refresh_token'))->toBe([]);
});

it('keeps the sign-in when renewing fails for a reason that may pass', function (Closure $responder): void {
    $this->auth->respondTo('token', $responder);
    $this->travel(2)->hours();

    $failure = listingFailure($this->connection);
    $this->connection->refresh();

    expect($failure?->failure)->toBe(DownstreamFailure::Unreachable)
        ->and($failure?->getMessage())->toBe('Nexus couldn\'t renew the sign-in: the server\'s sign-in service didn\'t answer properly. Try again in a moment.')
        ->and($this->connection->status)->toBe(ConnectionStatus::Connected)
        ->and($this->connection->secrets->get('refresh_token'))->toBe('refresh-token-1');
})->with([
    'unreachable' => [fn (Request $request): PromiseInterface => Http::failedConnection()($request)],
    'a server error' => [fn (): PromiseInterface => Http::response('Down for maintenance', 503)],
    'rate limited' => [fn (): PromiseInterface => Http::response(['error' => 'slow_down'], 429)],
    'temporarily unavailable' => [fn (): PromiseInterface => Http::response(['error' => 'temporarily_unavailable'], 400)],
    'no access token' => [fn (): PromiseInterface => Http::response(['token_type' => 'Bearer'])],
]);

it('renews once when a request that waited for the lock finds the token another request just renewed', function (): void {
    $this->travel(2)->hours();
    $stale = Connection::query()->findOrFail($this->connection->id);

    expect(resolve(ConnectionTokens::class)->accessToken($this->connection, FakeMcpServer::DEFAULT_URL))->toBe('access-token-2')
        ->and($stale->secrets->get('access_token'))->toBe('access-token-1')
        ->and(resolve(ConnectionTokens::class)->accessToken($stale, FakeMcpServer::DEFAULT_URL))->toBe('access-token-2')
        ->and($this->auth->tokenRequests('refresh_token'))->toHaveCount(1)
        ->and($stale->refresh()->status)->toBe(ConnectionStatus::Connected)
        ->and($stale->secrets->get('refresh_token'))->toBe('refresh-token-2');
});

it('doesn\'t renew while another request holds the Connection\'s lock, and gives up after waiting', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $this->travel(2)->hours();
    $waitedFrom = now();
    $lock = Cache::lock("connections.{$this->connection->id}.oauth-tokens", 30);
    $lock->get();

    try {
        $failure = listingFailure($this->connection);
    } finally {
        $lock->release();
    }

    expect($failure?->failure)->toBe(DownstreamFailure::Timeout)
        ->and($failure?->getMessage())->toBe('Nexus is still renewing the sign-in in another request. Try again in a moment.')
        ->and($this->auth->tokenRequests('refresh_token'))->toBe([])
        ->and($this->connection->refresh()->secrets->get('refresh_token'))->toBe('refresh-token-1')
        ->and($waitedFrom->diffInSeconds(now()))->toBeGreaterThanOrEqual(14.0);
});

it('renews late in a session only within the session\'s time left, then times out like any request', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $this->travel(3600 - 100)->seconds();
    $this->server->beforeAnswering('initialize', fn () => $this->travel(50)->seconds());
    $startedAt = now();
    $listingsBefore = count($this->server->received('tools/list'));
    $lock = Cache::lock("connections.{$this->connection->id}.oauth-tokens", 300);
    $lock->get();

    try {
        $failure = listingFailure($this->connection);
    } finally {
        $lock->release();
    }

    expect($failure?->failure)->toBe(DownstreamFailure::Timeout)
        ->and($failure?->getMessage())->toBe('The server took too long to answer, so Nexus stopped waiting.')
        ->and($this->auth->tokenRequests('refresh_token'))->toBe([])
        ->and($this->server->received('tools/list'))->toHaveCount($listingsBefore)
        ->and($startedAt->diffInSeconds(now()))->toBeLessThanOrEqual(55.0);
});

it('stores the tools listed with a token renewed during the refresh', function (): void {
    $this->server->withTools([['name' => 'search'], ['name' => 'create_page']]);
    $this->travel(2)->hours();

    expect(resolve(RefreshCatalog::class)->handle($this->connection))->toBeTrue()
        ->and($this->connection->refresh()->tools()->pluck('name')->all())->toEqualCanonicalizing(['search', 'create_page'])
        ->and($this->connection->secrets->get('access_token'))->toBe('access-token-2');
});

it('drops a refresh overtaken by a new sign-in', function (): void {
    $this->server->withTools([['name' => 'search'], ['name' => 'create_page']])
        ->beforeAnswering('tools/list', function (): void {
            $signedInAgain = Connection::query()->findOrFail($this->connection->id);
            $signedInAgain->settings = [...$signedInAgain->settings ?? [], 'signed_in_at' => now()->addSecond()->format('Y-m-d\\TH:i:s.uP')];
            $signedInAgain->save();
        });

    expect(resolve(RefreshCatalog::class)->handle($this->connection))->toBeFalse()
        ->and($this->connection->tools()->pluck('name')->all())->toBe(['search']);
});

it('tells a Connection that was never signed in to sign in, without asking its server', function (): void {
    $connection = Connection::factory()->for($this->user)->oauth()->create();
    $requestsBefore = count($this->server->requests());

    expect(listingFailure($connection)?->getMessage())->toBe('Nexus isn\'t signed in to this server. Reconnect to sign in.')
        ->and(count($this->server->requests()))->toBe($requestsBefore);
});

it('says the server refused the token when it no longer accepts it', function (): void {
    $this->auth->issuingTokensFor(3600);
    $this->server->requireOAuth(FakeAuthorizationServer::at('https://other-auth.example.com'));

    $failure = listingFailure($this->connection);

    expect($failure?->failure)->toBe(DownstreamFailure::NeedsSignIn)
        ->and($failure?->getMessage())->toBe('The server refused the credentials Nexus sent (HTTP 401).');
});

/**
 * Move the Connection to another server through the action the Connection
 * page uses, as a request that doesn't hold the renewal's lock would: one
 * that runs after a hung renewal's lock expired.
 */
function moveToAnotherServer(Connection $connection): void
{
    Cache::lock("connections.{$connection->id}.oauth-tokens")->forceRelease();

    resolve(UpdateConnectionServer::class)->handle(Connection::query()->findOrFail($connection->id), [
        'url' => 'https://other.example.com/mcp',
        'auth_type' => ConnectionAuthType::OAuth,
        'header_name' => null,
    ]);
}

it('keeps none of a renewal\'s tokens when the Connection moves to another server while it runs', function (): void {
    $this->travel(2)->hours();
    $this->auth->beforeAnswering('token', fn () => moveToAnotherServer($this->connection));

    $failure = listingFailure($this->connection);
    $stored = Connection::query()->findOrFail($this->connection->id);

    expect($this->auth->tokenRequests('refresh_token'))->toHaveCount(1)
        ->and($failure?->failure)->toBe(DownstreamFailure::NeedsSignIn)
        ->and($stored->url)->toBe('https://other.example.com/mcp')
        ->and($stored->status)->toBe(ConnectionStatus::NeedsAuth)
        ->and($stored->settings)->toBeNull()
        ->and($stored->secrets->all())->toBe([]);
});

it('doesn\'t end the new sign-in when a renewal of the old one is refused after the Connection moved', function (): void {
    $this->travel(2)->hours();
    $this->auth->respondTo('token', function (): PromiseInterface {
        moveToAnotherServer($this->connection);

        return Http::response(['error' => 'invalid_grant'], 400);
    });

    listingFailure($this->connection);
    $stored = Connection::query()->findOrFail($this->connection->id);

    expect($stored->url)->toBe('https://other.example.com/mcp')
        ->and($stored->last_error)->toBeNull();
});

it('keeps tokens renewed after the Connection was read when its server settings are saved', function (): void {
    $stale = Connection::query()->findOrFail($this->connection->id);
    $this->travel(2)->hours();
    listingFailure($this->connection);

    $loaded = resolve(UpdateConnectionServer::class)->handle($stale, [
        'url' => FakeMcpServer::DEFAULT_URL,
        'auth_type' => ConnectionAuthType::OAuth,
        'header_name' => null,
    ]);

    $stored = Connection::query()->findOrFail($this->connection->id);

    expect($loaded)->toBeTrue()
        ->and($stored->status)->toBe(ConnectionStatus::Connected)
        ->and($stored->secrets->get('access_token'))->toBe('access-token-2')
        ->and($stored->secrets->get('refresh_token'))->toBe('refresh-token-2')
        ->and($this->auth->tokenRequests('refresh_token'))->toHaveCount(1);
});

it('waits for a renewal in progress before changing the Connection\'s server, and says so if it takes too long', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $lock = Cache::lock("connections.{$this->connection->id}.oauth-tokens", 30);
    $lock->get();

    try {
        Livewire::test('pages::connections.show', ['connection' => $this->connection])
            ->set('url', 'https://other.example.com/mcp')
            ->call('saveServer')
            ->assertHasErrors(['server' => 'Nexus is renewing this Connection\'s sign-in. Try again in a moment.'])
            ->assertSeeText('Nexus is renewing this Connection\'s sign-in. Try again in a moment.')
            ->assertNoRedirect();
    } finally {
        $lock->release();
    }

    expect($this->connection->refresh()->url)->toBe(FakeMcpServer::DEFAULT_URL)
        ->and($this->connection->secrets->get('access_token'))->toBe('access-token-1');
});

it('doesn\'t keep a client registered with a server the Connection moved away from meanwhile', function (): void {
    $server = FakeMcpServer::at('https://fresh.example.com/mcp')->requireOAuth(FakeAuthorizationServer::at('https://auth.fresh.example.com'));
    $connection = Connection::factory()->for($this->user)->oauth()->create(['url' => 'https://fresh.example.com/mcp']);
    $server->authorizationServer()->beforeAnswering('register', fn () => moveToAnotherServer($connection));

    $this->get(route('connections.connect', $connection))
        ->assertRedirect(route('connections.show', $connection))
        ->assertSessionHas('toast.text', 'Nexus couldn\'t start signing in. The Connection changed while Nexus was registering with its server. Start again.');

    $stored = $connection->refresh();

    expect($stored->url)->toBe('https://other.example.com/mcp')
        ->and($stored->settings)->toBeNull()
        ->and($stored->secrets->all())->toBe([]);
});

it('gives a session opened for the old server no token once the Connection moved and signed in to a new one', function (bool $expired, bool $refreshedInPlace): void {
    if ($expired) {
        $this->travel(2)->hours();
    }

    $session = resolve(DownstreamClient::class)->session($this->connection);
    $replacement = FakeMcpServer::at('https://replacement.example.com/mcp')
        ->requireOAuth(FakeAuthorizationServer::at('https://auth.replacement.example.com'))
        ->withTools([['name' => 'search']]);

    Livewire::test('pages::connections.show', ['connection' => Connection::query()->findOrFail($this->connection->id)])
        ->set('url', 'https://replacement.example.com/mcp')
        ->call('saveServer')
        ->assertRedirect(route('connections.connect', $this->connection));
    ConnectionOAuthFlow::signIn($this, Connection::query()->findOrFail($this->connection->id), $replacement->authorizationServer());

    if ($refreshedInPlace) {
        $this->connection->refresh();
    }

    expect($this->connection->url)->toBe($refreshedInPlace ? 'https://replacement.example.com/mcp' : FakeMcpServer::DEFAULT_URL);

    $requestsBefore = count($this->server->requests());

    try {
        $session->listTools();
        $failure = null;
    } catch (DownstreamRequestFailed $failed) {
        $failure = $failed;
    }

    expect(Connection::query()->findOrFail($this->connection->id)->hasAccessToken())->toBeTrue()
        ->and($failure?->failure)->toBe(DownstreamFailure::NeedsSignIn)
        ->and(array_slice($this->server->requests(), $requestsBefore))->toBe([])
        ->and($this->auth->tokenRequests('refresh_token'))->toBe([]);
})->with([
    'a stale model with an expired token' => [true, false],
    'a model refreshed in place, its token expired' => [true, true],
    'a model refreshed in place, its token current' => [false, true],
]);
