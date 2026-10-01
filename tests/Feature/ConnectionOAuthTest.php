<?php

namespace Tests\Feature;

use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Filament\Resources\Connections\ConnectionResource;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeMcpServer;
use Tests\TestCase;

class ConnectionOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected Connection $connection;

    protected FakeMcpServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Connection::factory()->create([
            'auth_type' => ConnectionAuthType::OAuth,
            'status' => ConnectionStatus::Pending,
            'url' => 'https://svc.example.com/mcp',
        ]);

        $this->server = new FakeMcpServer;
        $this->server->requireAuthorization = 'Bearer issued-access';
        $this->server->toolsJson = '[{"name":"list_issues","inputSchema":{"type":"object"},"annotations":{"readOnlyHint":true}}]';

        Http::fake([
            'https://svc.example.com/mcp' => $this->server->handler(),
            'https://svc.example.com/.well-known/oauth-protected-resource/mcp' => Http::response([
                'resource' => 'https://svc.example.com/mcp',
                'authorization_servers' => ['https://auth.example.com'],
                'scopes_supported' => ['read', 'write'],
            ]),
            'https://auth.example.com/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.example.com',
                'authorization_endpoint' => 'https://auth.example.com/authorize',
                'token_endpoint' => 'https://auth.example.com/token',
                'registration_endpoint' => 'https://auth.example.com/register',
                'code_challenge_methods_supported' => ['S256'],
            ]),
            'https://auth.example.com/register' => Http::response(['client_id' => 'registered-client'], 201),
            'https://auth.example.com/token' => fn (Request $request) => Http::response($this->tokenResponse($request)),
        ]);
    }

    /** Extra fields the fake authorization server adds to its token responses. */
    protected array $tokenExtras = [];

    /** Issue a distinct token per authorization code, to tell sign-ins apart. */
    protected bool $tokenPerCode = false;

    /**
     * @return array<string, mixed>
     */
    protected function tokenResponse(Request $request): array
    {
        $suffix = $this->tokenPerCode && isset($request['code']) ? '-'.$request['code'] : '';

        return [
            'access_token' => "issued-access{$suffix}",
            'refresh_token' => "issued-refresh{$suffix}",
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            ...$this->tokenExtras,
        ];
    }

    /**
     * Start a sign-in and return the authorize URL's query parameters.
     *
     * @return array<string, string>
     */
    protected function startSignIn(Connection $connection): array
    {
        $location = $this->actingAs($connection->user)
            ->get(route('connections.oauth.connect', $connection))
            ->headers->get('Location');

        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_connecting_registers_nexus_and_sends_the_user_to_the_authorization_server(): void
    {
        $response = $this->actingAs($this->connection->user)
            ->get(route('connections.oauth.connect', $this->connection));

        $location = $response->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://auth.example.com/authorize?', $location);
        $this->assertSame('registered-client', $query['client_id']);
        $this->assertSame(route('oauth.callback'), $query['redirect_uri']);
        $this->assertSame('https://svc.example.com/mcp', $query['resource']);
        $this->assertSame('read write', $query['scope'], 'The scope comes from the 401 challenge, not the mcp:use default.');
        $this->assertSame('S256', $query['code_challenge_method']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.example.com/register'
            && $request['client_name'] === 'Nexus'
            && $request['redirect_uris'] === [route('oauth.callback')]);
    }

    public function test_the_callback_stores_tokens_encrypted_and_loads_the_tools(): void
    {
        $this->actingAs($this->connection->user);

        $location = $this->get(route('connections.oauth.connect', $this->connection))->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->get(route('oauth.callback', ['code' => 'auth-code', 'state' => $query['state']]))->assertRedirect();

        $connection = $this->connection->fresh();
        $this->assertSame(ConnectionStatus::Active, $connection->status);
        $this->assertSame('issued-access', $connection->secret('access_token'));
        $this->assertSame('issued-refresh', $connection->secret('refresh_token'));
        $this->assertSame('registered-client', $connection->secret('client_id'));
        $this->assertSame(['list_issues'], $connection->tools()->pluck('name')->all());

        $stored = (string) DB::table('connections')->where('id', $connection->id)->value('secrets');
        $this->assertStringNotContainsString('issued-access', $stored);
        $this->assertStringNotContainsString('issued-refresh', $stored);
    }

    public function test_a_callback_with_an_unknown_state_touches_nothing(): void
    {
        $this->startSignIn($this->connection);

        $this->get(route('oauth.callback', ['code' => 'auth-code', 'state' => 'forged']))
            ->assertRedirect(ConnectionResource::getUrl('index', panel: 'app'));

        $connection = $this->connection->fresh();
        $this->assertSame(ConnectionStatus::Pending, $connection->status);
        $this->assertNull($connection->secret('access_token'));
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://auth.example.com/token');
    }

    public function test_the_provider_is_asked_to_show_its_account_chooser(): void
    {
        $this->assertSame('select_account', $this->startSignIn($this->connection)['prompt']);
    }

    public function test_two_sign_ins_to_the_same_server_can_run_at_once(): void
    {
        $this->tokenPerCode = true;
        $this->server->requireAuthorization = ['Bearer issued-access-a', 'Bearer issued-access-b'];

        $second = Connection::factory()->for($this->connection->user)->create([
            'handle' => 'second',
            'auth_type' => ConnectionAuthType::OAuth,
            'status' => ConnectionStatus::Pending,
            'url' => $this->connection->url,
        ]);

        $first = $this->startSignIn($this->connection);
        $other = $this->startSignIn($second);

        $this->get(route('oauth.callback', ['code' => 'a', 'state' => $first['state']]))->assertRedirect();

        $this->assertSame('issued-access-a', $this->connection->fresh()->secret('access_token'));
        $this->assertNull($second->fresh()->secret('access_token'));

        $this->get(route('oauth.callback', ['code' => 'b', 'state' => $other['state']]))->assertRedirect();

        $this->assertSame('issued-access-b', $second->fresh()->secret('access_token'));
        $this->assertSame('issued-access-a', $this->connection->fresh()->secret('access_token'));
    }

    public function test_an_id_token_in_the_token_response_labels_the_account(): void
    {
        $claims = rtrim(strtr(base64_encode(json_encode(['email' => 'ada@example.com'])), '+/', '-_'), '=');
        $this->tokenExtras = ['id_token' => "header.{$claims}.signature"];

        $query = $this->startSignIn($this->connection);
        $this->get(route('oauth.callback', ['code' => 'auth-code', 'state' => $query['state']]));

        $this->assertSame('ada@example.com', $this->connection->fresh()->account_identity);
    }

    public function test_a_workspace_in_the_token_response_labels_the_account(): void
    {
        $this->tokenExtras = ['team' => ['id' => 'T123', 'name' => 'BetterWorld']];

        $query = $this->startSignIn($this->connection);
        $this->get(route('oauth.callback', ['code' => 'auth-code', 'state' => $query['state']]));

        $this->assertSame('BetterWorld', $this->connection->fresh()->account_identity);
    }

    public function test_a_brought_client_id_is_used_instead_of_registering(): void
    {
        $this->connection->forceFill(['settings' => ['oauth_client_id' => 'my-slack-app']])
            ->putSecrets(['oauth_client_secret' => 'shh'])
            ->save();

        $location = $this->actingAs($this->connection->user)
            ->get(route('connections.oauth.connect', $this->connection))
            ->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('my-slack-app', $query['client_id']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://auth.example.com/register');
    }

    public function test_only_the_owner_can_connect_a_connection(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('connections.oauth.connect', $this->connection))
            ->assertForbidden();
    }

    public function test_the_client_metadata_document_describes_nexus(): void
    {
        $this->getJson(route('oauth.client-metadata'))
            ->assertOk()
            ->assertJsonPath('client_id', route('oauth.client-metadata'))
            ->assertJsonPath('redirect_uris', [route('oauth.callback')])
            ->assertJsonPath('token_endpoint_auth_method', 'none');
    }

    public function test_a_resource_named_by_its_origin_like_slack_is_accepted_and_used(): void
    {
        $this->fakeAuthorizationServer(resource: 'https://slackish.example.com', metadataPath: '/.well-known/oauth-protected-resource');
        $connection = $this->connectionTo('https://slackish.example.com/mcp');

        $query = $this->startSignIn($connection);

        $this->assertSame('https://slackish.example.com', $query['resource']);
        $this->assertSame('https://slackish.example.com', $connection->fresh()->setting('oauth_resource'));

        $this->get(route('oauth.callback', ['code' => 'auth-code', 'state' => $query['state']]))->assertRedirect();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth2.example.com/token'
            && $request['resource'] === 'https://slackish.example.com');
        $this->assertSame('issued-access', $connection->fresh()->secret('access_token'));
    }

    public function test_a_resource_on_another_origin_is_refused(): void
    {
        $this->fakeAuthorizationServer(resource: 'https://evil.example.com/mcp', metadataPath: '/.well-known/oauth-protected-resource');
        $connection = $this->connectionTo('https://slackish.example.com/mcp');

        $this->actingAs($connection->user)->get(route('connections.oauth.connect', $connection))->assertRedirect();

        $connection->refresh();
        $this->assertSame(ConnectionStatus::Error, $connection->status);
        $this->assertStringContainsString('did not match', $connection->status_message);
    }

    public function test_an_authorization_server_listed_with_a_trailing_slash_like_google_is_found(): void
    {
        $this->fakeAuthorizationServer(resource: 'https://slackish.example.com/mcp', metadataPath: '/.well-known/oauth-protected-resource/mcp', issuerListedAs: 'https://auth2.example.com/');
        $connection = $this->connectionTo('https://slackish.example.com/mcp');

        $this->assertArrayHasKey('state', $this->startSignIn($connection));
    }

    public function test_google_is_asked_for_offline_access_so_it_issues_a_refresh_token(): void
    {
        $this->fakeAuthorizationServer(resource: 'https://slackish.example.com/mcp', metadataPath: '/.well-known/oauth-protected-resource/mcp', authorizeAt: 'https://accounts.google.com/o/oauth2/v2/auth');
        $connection = $this->connectionTo('https://slackish.example.com/mcp');

        $query = $this->startSignIn($connection);

        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent select_account', $query['prompt']);
    }

    public function test_a_connectors_preset_scope_is_requested_when_the_server_does_not_ask_for_one(): void
    {
        config(['nexus.connectors.gmail' => ['client_id' => 'google-app', 'client_secret' => 'google-secret']]);
        $this->server->challengeScope = null;
        $this->connection->forceFill(['connector' => 'gmail'])->save();

        $query = $this->startSignIn($this->connection);

        $this->assertSame('google-app', $query['client_id']);
        $this->assertSame('https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.compose', $query['scope']);
    }

    /**
     * A second fake server at https://slackish.example.com, with its own
     * protected resource metadata and authorization server (auth2), so the
     * stubs from setUp() don't answer first.
     */
    protected function fakeAuthorizationServer(string $resource, string $metadataPath, string $issuerListedAs = 'https://auth2.example.com', string $authorizeAt = 'https://auth2.example.com/authorize'): void
    {
        $server = new FakeMcpServer('https://slackish.example.com/mcp');
        $server->requireAuthorization = 'Bearer issued-access';
        $server->challengeScope = null;
        $server->resourceMetadataUrl = "https://slackish.example.com{$metadataPath}";

        Http::fake([
            'https://slackish.example.com/mcp' => $server->handler(),
            "https://slackish.example.com{$metadataPath}" => Http::response([
                'resource' => $resource,
                'authorization_servers' => [$issuerListedAs],
            ]),
            'https://auth2.example.com/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth2.example.com',
                'authorization_endpoint' => $authorizeAt,
                'token_endpoint' => 'https://auth2.example.com/token',
                'registration_endpoint' => 'https://auth2.example.com/register',
                'code_challenge_methods_supported' => ['S256'],
            ]),
            'https://auth2.example.com/register' => Http::response(['client_id' => 'registered-client'], 201),
            'https://auth2.example.com/token' => fn (Request $request) => Http::response($this->tokenResponse($request)),
        ]);
    }

    protected function connectionTo(string $url): Connection
    {
        return Connection::factory()->for($this->connection->user)->create([
            'handle' => 'other',
            'auth_type' => ConnectionAuthType::OAuth,
            'status' => ConnectionStatus::Pending,
            'url' => $url,
        ]);
    }
}
