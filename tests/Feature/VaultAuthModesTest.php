<?php

namespace Tests\Feature;

use App\Enums\VaultAuthMode;
use App\Filament\Resources\Vaults\Pages\CreateVault;
use App\Filament\Resources\Vaults\Pages\EditVault;
use App\Filament\Resources\Vaults\RelationManagers\ConnectedAppsRelationManager;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\ToolCallLog;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultToken;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Livewire\Livewire;
use Tests\Support\FakeMcpServer;
use Tests\TestCase;

class VaultAuthModesTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    }

    public function test_a_signed_url_vault_opens_with_its_url_alone(): void
    {
        $vault = $this->vault(VaultAuthMode::SignedUrl);
        (new FakeMcpServer)->fake();

        $this->rpc($vault->signedUrl(), 'tools/call', ['name' => 'svc__search', 'arguments' => []])
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        $this->assertSame('Signed URL', ToolCallLog::query()->sole()->via);
    }

    public function test_a_tampered_or_rotated_signed_url_is_refused(): void
    {
        $vault = $this->vault(VaultAuthMode::SignedUrl);
        $original = $vault->signedUrl();

        $this->rpc(str_replace('v=1', 'v=2', $original), 'tools/list')->assertUnauthorized();
        $this->rpc($vault->endpointUrl(), 'tools/list')->assertUnauthorized();

        $vault->rotateSignedUrl();

        $this->rpc($original, 'tools/list')->assertUnauthorized();
        $this->rpc($vault->fresh()->signedUrl(), 'tools/list')->assertOk();
    }

    public function test_each_vault_accepts_only_its_own_mode(): void
    {
        $vault = $this->vault(VaultAuthMode::SignedUrl);
        [, $token] = VaultToken::issue($vault, 'Old token');

        $this->rpc($vault->endpointUrl(), 'tools/list', bearer: $token)->assertUnauthorized();
    }

    public function test_an_oauth_vault_points_clients_at_its_metadata(): void
    {
        $vault = $this->vault(VaultAuthMode::OAuth);

        $challenge = $this->rpc($vault->endpointUrl(), 'tools/list')->assertUnauthorized()->headers->get('WWW-Authenticate');

        $this->assertStringContainsString('resource_metadata="'.route('mcp.oauth.protected-resource', $vault->public_id).'"', $challenge);

        $this->getJson(route('mcp.oauth.protected-resource', $vault->public_id))
            ->assertOk()
            ->assertJsonPath('resource', $vault->endpointUrl())
            ->assertJsonPath('authorization_servers', [$vault->oauthIssuer()]);

        $this->getJson("/.well-known/oauth-authorization-server/oauth/vaults/{$vault->public_id}")
            ->assertOk()
            ->assertJsonPath('issuer', $vault->oauthIssuer())
            ->assertJsonPath('registration_endpoint', route('mcp.oauth.register', $vault->public_id))
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_vaults_in_other_modes_publish_no_oauth_metadata(): void
    {
        $vault = $this->vault(VaultAuthMode::Token);

        $this->getJson(route('mcp.oauth.protected-resource', $vault->public_id))->assertNotFound();
        $this->postJson(route('mcp.oauth.register', $vault->public_id), ['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])->assertNotFound();
    }

    public function test_an_app_signs_in_with_the_owners_approval_and_opens_the_vault(): void
    {
        $vault = $this->vault(VaultAuthMode::OAuth);
        (new FakeMcpServer)->fake();

        $clientId = $this->register($vault, 'Claude');
        $this->assertTrue($vault->oauthClients()->whereKey($clientId)->exists());

        $tokens = $this->signIn($this->owner, $clientId, expectConsentFor: $vault);

        $this->rpc($vault->endpointUrl(), 'tools/call', ['name' => 'svc__search', 'arguments' => []], bearer: $tokens['access_token'])
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        $this->assertSame('OAuth: Claude', ToolCallLog::query()->sole()->via);
        $this->assertNotEmpty($tokens['refresh_token']);
    }

    public function test_an_apps_token_never_opens_a_different_vault(): void
    {
        $vault = $this->vault(VaultAuthMode::OAuth);
        $otherVault = $this->vault(VaultAuthMode::OAuth);

        $tokens = $this->signIn($this->owner, $this->register($vault, 'Claude'), expectConsentFor: $vault);

        $this->rpc($otherVault->endpointUrl(), 'tools/list', bearer: $tokens['access_token'])->assertUnauthorized();
    }

    public function test_someone_else_cannot_approve_an_app_for_your_vault(): void
    {
        $vault = $this->vault(VaultAuthMode::OAuth);
        $intruder = User::factory()->create();
        $clientId = $this->register($vault, 'Sneaky');

        $this->actingAs($intruder)
            ->get($this->authorizeUrl($clientId))
            ->assertOk()
            ->assertSee("isn't connected to one of your vaults", escape: false)
            ->assertDontSee('>Approve</button>', escape: false);

        // Even pushing the approval through by hand yields a useless token.
        $tokens = $this->signIn($intruder, $clientId);

        $this->rpc($vault->endpointUrl(), 'tools/list', bearer: $tokens['access_token'])->assertUnauthorized();
    }

    public function test_revoking_a_connected_app_cuts_it_off(): void
    {
        $vault = $this->vault(VaultAuthMode::OAuth);
        $clientId = $this->register($vault, 'Claude');
        $tokens = $this->signIn($this->owner, $clientId, expectConsentFor: $vault);

        Filament::setCurrentPanel('app');
        $this->actingAs($this->owner);

        Livewire::test(ConnectedAppsRelationManager::class, ['ownerRecord' => $vault, 'pageClass' => EditVault::class])
            ->assertCanSeeTableRecords([Client::query()->find($clientId)])
            ->callAction(TestAction::make('revoke')->table(Client::query()->find($clientId)));

        $this->assertTrue(Client::query()->find($clientId)->revoked);
        $this->rpc($vault->endpointUrl(), 'tools/list', bearer: $tokens['access_token'])->assertUnauthorized();
    }

    public function test_the_sign_in_mode_is_chosen_when_creating_a_vault(): void
    {
        Filament::setCurrentPanel('app');
        $this->actingAs($this->owner);

        Livewire::test(CreateVault::class)
            ->assertFormSet(['auth_mode' => VaultAuthMode::Token])
            ->fillForm(['name' => 'Phone', 'auth_mode' => VaultAuthMode::OAuth->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(VaultAuthMode::OAuth, Vault::query()->where('name', 'Phone')->sole()->auth_mode);
    }

    public function test_rotating_the_signed_url_from_the_vault_page(): void
    {
        Filament::setCurrentPanel('app');
        $this->actingAs($this->owner);
        $vault = $this->vault(VaultAuthMode::SignedUrl);

        Livewire::test(EditVault::class, ['record' => $vault->getKey()])
            ->assertActionVisible('rotateUrl')
            ->callAction('rotateUrl');

        $this->assertSame(2, $vault->fresh()->signed_url_version);
    }

    public function test_setup_instructions_match_the_vaults_mode(): void
    {
        Filament::setCurrentPanel('app');
        $this->actingAs($this->owner);

        $oauth = $this->vault(VaultAuthMode::OAuth);
        Livewire::test(EditVault::class, ['record' => $oauth->getKey()])
            ->assertActionHidden('rotateUrl')
            ->mountAction('setup')
            ->assertMountedActionModalSee(["codex mcp login {$oauth->clientKey()}", 'Add custom connector', $oauth->endpointUrl()])
            ->assertMountedActionModalDontSee('bearer_token_env_var');

        $signed = $this->vault(VaultAuthMode::SignedUrl);
        Livewire::test(EditVault::class, ['record' => $signed->getKey()])
            ->mountAction('setup')
            ->assertMountedActionModalSee(['The URL is the credential', 'signature='])
            ->assertMountedActionModalDontSee('bearer_token_env_var');
    }

    protected function vault(VaultAuthMode $mode): Vault
    {
        $connection = Connection::query()->where('user_id', $this->owner->id)->where('handle', 'svc')->first()
            ?? Connection::factory()->for($this->owner)->create(['handle' => 'svc', 'url' => 'https://svc.example.com/mcp']);
        $connection->tools()->firstOrCreate(['name' => 'search'], ConnectionTool::factory()->readOnly()->make(['name' => 'search'])->only(['definition', 'definition_hash', 'read_only', 'destructive']));

        $vault = Vault::factory()->for($this->owner)->create(['auth_mode' => $mode]);
        $vault->connections()->attach($connection);

        return $vault;
    }

    protected function register(Vault $vault, string $name): string
    {
        return $this->postJson(route('mcp.oauth.register', $vault->public_id), [
            'client_name' => $name,
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertCreated()->json('client_id');
    }

    /**
     * Run the authorization-code flow with PKCE as an MCP client would:
     * consent screen, approval, then the code exchange.
     *
     * @return array<string, mixed>
     */
    protected function signIn(User $user, string $clientId, ?Vault $expectConsentFor = null): array
    {
        $verifier = Str::random(64);

        $consent = $this->actingAs($user)->get($this->authorizeUrl($clientId, $verifier))->assertOk();

        if ($expectConsentFor !== null) {
            $consent->assertSee("use your “{$expectConsentFor->name}” vault", escape: false)->assertSee('>Approve</button>', escape: false);
        }

        $location = $this->post(route('passport.authorizations.approve'), [
            'state' => '',
            'client_id' => $clientId,
            'auth_token' => session('authToken'),
        ])->assertRedirect()->headers->get('Location');

        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state']);

        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_verifier' => $verifier,
            'code' => $query['code'],
        ])->assertOk()->json();
    }

    protected function authorizeUrl(string $clientId, string $verifier = 'unused-verifier-unused-verifier-unused-verifier'): string
    {
        return '/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'xyz',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function rpc(string $url, string $method, array $params = [], ?string $bearer = null): TestResponse
    {
        // Each MCP request arrives fresh; don't let a guard remember the last one.
        Auth::forgetGuards();

        return $this->withHeaders(array_filter([
            'Authorization' => $bearer === null ? null : "Bearer {$bearer}",
            'Accept' => 'application/json, text/event-stream',
        ]))->postJson($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }
}
