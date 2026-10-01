<?php

namespace Tests\Feature;

use App\Connectors\ConnectorCatalog;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Filament\Resources\Connections\ConnectionResource;
use App\Filament\Resources\Connections\Pages\AddConnection;
use App\Filament\Resources\Connections\Pages\EditConnection;
use App\Mcp\Downstream\OAuthClients;
use App\Mcp\Downstream\OAuthTokens;
use App\Models\Connection;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Client\OAuth\TokenSet;
use Livewire\Livewire;
use Tests\TestCase;

class ConnectorGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        Filament::setCurrentPanel('app');
    }

    public function test_the_gallery_lists_every_connector_and_a_custom_option(): void
    {
        $response = $this->get(ConnectionResource::getUrl('add'))->assertOk();

        foreach (ConnectorCatalog::all() as $connector) {
            $response->assertSee($connector->name);
        }

        $response->assertSee('Custom MCP server');
    }

    public function test_every_connector_points_at_an_https_mcp_server(): void
    {
        foreach (ConnectorCatalog::all() as $key => $connector) {
            $this->assertSame($key, $connector->key);
            $this->assertStringStartsWith('https://', $connector->url);
            $this->assertMatchesRegularExpression(Connection::HANDLE_PATTERN, $connector->key);
        }
    }

    public function test_connecting_a_self_registering_service_only_needs_a_name(): void
    {
        $this->connect('linear', ['name' => 'Linear', 'handle' => 'linear', 'description' => 'Work issues'])
            ->assertHasNoActionErrors()
            ->assertRedirect(route('connections.oauth.connect', Connection::query()->sole()));

        $connection = Connection::query()->sole();
        $this->assertSame('linear', $connection->connector);
        $this->assertSame('https://mcp.linear.app/mcp', $connection->url);
        $this->assertSame(ConnectionAuthType::OAuth, $connection->auth_type);
        $this->assertSame(ConnectionStatus::Pending, $connection->status);
        $this->assertSame('Work issues', $connection->description);
        $this->assertTrue($connection->user->is($this->user));
    }

    public function test_a_second_account_gets_its_own_name_and_handle(): void
    {
        Connection::factory()->for($this->user)->create(['connector' => 'linear', 'handle' => 'linear']);

        Livewire::test(AddConnection::class)
            ->mountAction(TestAction::make('connect_linear')->schemaComponent('connector-linear', schema: 'content'))
            ->assertActionDataSet(['name' => 'Linear 2', 'handle' => 'linear-2']);
    }

    public function test_handles_must_be_unique_for_the_user(): void
    {
        Connection::factory()->for($this->user)->create(['handle' => 'linear']);

        $this->connect('linear', ['name' => 'Linear', 'handle' => 'linear'])
            ->assertHasActionErrors(['handle' => 'unique']);
    }

    public function test_without_a_deployment_app_slack_asks_for_the_users_own(): void
    {
        $this->connect('slack', ['name' => 'Slack', 'handle' => 'slack'])
            ->assertHasActionErrors(['oauth_client_id' => 'required', 'oauth_client_secret' => 'required']);

        $this->connect('slack', ['name' => 'Slack', 'handle' => 'slack', 'oauth_client_id' => 'my-app', 'oauth_client_secret' => 'my-secret'])
            ->assertHasNoActionErrors();

        $connection = Connection::query()->sole();
        $this->assertSame('my-app', $connection->setting('oauth_client_id'));
        $this->assertSame('my-secret', $connection->secret('oauth_client_secret'));
        $this->assertStringNotContainsString('my-secret', (string) DB::table('connections')->value('secrets'));
        $this->assertSame(['my-app', 'my-secret'], app(OAuthClients::class)->credentials($connection));
    }

    public function test_with_a_deployment_app_users_only_sign_in(): void
    {
        config(['nexus.connectors.slack' => ['client_id' => 'deployment-app', 'client_secret' => 'deployment-secret']]);

        $this->connect('slack', ['name' => 'Slack', 'handle' => 'slack'])->assertHasNoActionErrors();

        $connection = Connection::query()->sole();
        $this->assertSame(['deployment-app', 'deployment-secret'], app(OAuthClients::class)->credentials($connection));

        app(OAuthTokens::class)->store($connection, new TokenSet('access', 'refresh', clientId: 'deployment-app', clientSecret: 'deployment-secret'));

        $this->assertNull($connection->fresh()->secret('client_secret'), "The deployment's secret is read from config, never copied per connection.");
    }

    public function test_a_connectors_server_and_sign_in_method_cannot_be_edited(): void
    {
        $connection = Connection::factory()->for($this->user)->withOAuthTokens()->create([
            'connector' => 'notion',
            'url' => 'https://mcp.notion.com/mcp',
        ]);

        Livewire::test(EditConnection::class, ['record' => $connection->getKey()])
            ->assertFormFieldDisabled('url')
            ->assertFormFieldDisabled('auth_type')
            ->fillForm(['name' => 'Notion (Work)'])
            ->call('save')
            ->assertHasNoFormErrors();

        $connection = Connection::query()->find($connection->getKey());
        $this->assertSame('Notion (Work)', $connection->name);
        $this->assertSame('https://mcp.notion.com/mcp', $connection->url);
        $this->assertSame('access-1', $connection->secret('access_token'));
    }

    public function test_connecting_respects_the_account_limit(): void
    {
        config(['nexus.limits.connections_per_user' => 1]);
        Connection::factory()->for($this->user)->create();

        Livewire::test(AddConnection::class)
            ->assertActionDisabled(TestAction::make('connect_linear')->schemaComponent('connector-linear', schema: 'content'));
    }

    public function test_gmail_is_only_offered_once_this_deployment_has_a_google_app(): void
    {
        $gmail = TestAction::make('connect_gmail')->schemaComponent('connector-gmail', schema: 'content');

        Livewire::test(AddConnection::class)->assertActionDisabled($gmail);

        config(['nexus.connectors.gmail' => ['client_id' => 'google-app', 'client_secret' => 'google-secret']]);

        Livewire::test(AddConnection::class)
            ->assertActionEnabled($gmail)
            ->callAction($gmail, data: ['name' => 'Gmail', 'handle' => 'gmail'])
            ->assertHasNoActionErrors();

        $this->assertSame('https://gmailmcp.googleapis.com/mcp/v1', Connection::query()->sole()->url);
    }

    public function test_the_slack_manifest_carries_the_callback_and_every_scope_slack_asks_for(): void
    {
        $manifest = json_decode((string) ConnectorCatalog::find('slack')->appManifestJson('https://nexus.example/oauth/callback'), true);

        $slack = ConnectorCatalog::find('slack');

        $this->assertSame(['https://nexus.example/oauth/callback'], $manifest['oauth_config']['redirect_urls']);
        $this->assertCount(30, $slack->scopes);
        $this->assertSame($slack->scopes, $manifest['oauth_config']['scopes']['user']);
        $this->assertTrue($manifest['settings']['is_mcp_enabled']);
        $this->assertSame(implode(' ', $slack->scopes), $slack->scope());
    }

    public function test_editing_a_connection_keeps_settings_the_form_does_not_show(): void
    {
        $connection = Connection::factory()->for($this->user)->withOAuthTokens()->create([
            'connector' => 'slack',
            'url' => 'https://mcp.slack.com/mcp',
            'settings' => ['oauth_resource' => 'https://mcp.slack.com'],
        ]);

        Livewire::test(EditConnection::class, ['record' => $connection->getKey()])
            ->fillForm(['name' => 'Slack (Work)'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('https://mcp.slack.com', Connection::query()->find($connection->getKey())->setting('oauth_resource'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function connect(string $connector, array $data): mixed
    {
        return Livewire::test(AddConnection::class)
            ->callAction(TestAction::make("connect_{$connector}")->schemaComponent("connector-{$connector}", schema: 'content'), data: $data);
    }
}
