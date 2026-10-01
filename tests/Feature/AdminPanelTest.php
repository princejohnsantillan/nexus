<?php

namespace Tests\Feature;

use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Filament\Resources\Connections\ConnectionResource;
use App\Filament\Resources\Connections\Pages\CreateConnection;
use App\Filament\Resources\Connections\Pages\EditConnection;
use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Filament\Resources\ToolCallLogs\ToolCallLogResource;
use App\Filament\Resources\Vaults\Pages\CreateVault;
use App\Filament\Resources\Vaults\Pages\EditVault;
use App\Filament\Resources\Vaults\Pages\ManageVaultTools;
use App\Filament\Resources\Vaults\RelationManagers\TokensRelationManager;
use App\Filament\Resources\Vaults\VaultResource;
use App\Mcp\Vaults\VaultToolset;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultToken;
use App\Security\OutboundGuard;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FakeMcpServer;
use Tests\TestCase;

class AdminPanelTest extends TestCase
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

    public function test_users_only_see_their_own_connections(): void
    {
        $mine = Connection::factory()->for($this->user)->create();
        $theirs = Connection::factory()->create();

        Livewire::test(ListConnections::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_another_users_connection_cannot_be_opened(): void
    {
        $theirs = Connection::factory()->create();

        $this->get(ConnectionResource::getUrl('edit', ['record' => $theirs]))->assertNotFound();
    }

    public function test_creating_a_header_connection_encrypts_the_key_and_loads_its_tools(): void
    {
        $server = (new FakeMcpServer)->fake();
        $server->requireAuthorization = 'Bearer sk-live-1';
        $server->toolsJson = '[{"name":"list_scenes","inputSchema":{"type":"object"},"annotations":{"readOnlyHint":true}}]';

        Livewire::test(CreateConnection::class)
            ->fillForm([
                'name' => 'Excalidraw',
                'description' => 'Team diagrams',
                'handle' => 'excalidraw',
                'url' => 'https://svc.example.com/mcp',
                'auth_type' => ConnectionAuthType::Header->value,
                'settings.header_name' => 'Authorization',
                'header_value' => 'Bearer sk-live-1',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $connection = Connection::query()->sole();
        $this->assertTrue($connection->user->is($this->user));
        $this->assertSame('Bearer sk-live-1', $connection->secret('header_value'));
        $this->assertSame('Team diagrams', $connection->description);
        $this->assertSame(['list_scenes'], $connection->tools()->pluck('name')->all());
        $this->assertStringNotContainsString('sk-live-1', (string) DB::table('connections')->value('secrets'));
    }

    public function test_connection_urls_on_private_networks_are_rejected(): void
    {
        $this->app->instance(OutboundGuard::class, new OutboundGuard(resolver: fn (): array => ['10.0.0.5']));

        Livewire::test(CreateConnection::class)
            ->fillForm([
                'name' => 'Internal',
                'handle' => 'internal',
                'url' => 'https://internal.example.com/mcp',
                'auth_type' => ConnectionAuthType::None->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['url']);

        $this->assertDatabaseCount('connections', 0);
    }

    public function test_handles_only_need_to_be_unique_per_user(): void
    {
        Connection::factory()->create(['handle' => 'slack']);
        Connection::factory()->for($this->user)->create(['handle' => 'linear']);

        $form = fn (string $handle) => Livewire::test(CreateConnection::class)->fillForm([
            'name' => 'Slack',
            'handle' => $handle,
            'url' => 'https://svc.example.com/mcp',
            'auth_type' => ConnectionAuthType::OAuth->value,
        ])->call('create');

        $form('linear')->assertHasFormErrors(['handle' => 'unique']);
        $form('slack')->assertHasNoFormErrors();
    }

    public function test_issuing_a_token_shows_it_once_and_stores_only_its_hash(): void
    {
        $vault = Vault::factory()->for($this->user)->create();

        $component = Livewire::test(TokensRelationManager::class, ['ownerRecord' => $vault, 'pageClass' => EditVault::class])
            ->callAction(TestAction::make('issue')->table(), data: ['name' => 'Cursor', 'expires_in_days' => '30'])
            ->assertActionMounted('showToken');

        $plain = $component->instance()->mountedActions[0]['arguments']['token'];
        $token = VaultToken::query()->sole();

        $this->assertStringStartsWith('nxs_', $plain);
        $this->assertSame(VaultToken::hash($plain), $token->token_hash);
        $this->assertSame('Cursor', $token->name);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));
    }

    public function test_tools_can_be_switched_on_per_vault(): void
    {
        $connection = Connection::factory()->for($this->user)->create(['handle' => 'slack']);
        $send = ConnectionTool::factory()->for($connection)->create(['name' => 'send_message']);
        $vault = Vault::factory()->for($this->user)->create();
        $vault->connections()->attach($connection);

        Livewire::test(ManageVaultTools::class, ['record' => $vault->getKey()])
            ->assertCanSeeTableRecords([$send])
            ->call('updateTableColumnState', 'enabled', (string) $send->getKey(), true);

        $this->assertTrue(app(VaultToolset::class)->isEnabled($vault, $send));
    }

    public function test_another_users_vault_tools_cannot_be_opened(): void
    {
        $theirs = Vault::factory()->create();

        $this->get(VaultResource::getUrl('tools', ['record' => $theirs]))->assertNotFound();
    }

    public function test_creating_a_vault_assigns_it_to_the_user_with_their_connections(): void
    {
        $connection = Connection::factory()->for($this->user)->create();

        Livewire::test(CreateVault::class)
            ->fillForm(['name' => 'Demo', 'connections' => [$connection->getKey()]])
            ->call('create')
            ->assertHasNoFormErrors();

        $vault = Vault::query()->sole();
        $this->assertTrue($vault->user->is($this->user));
        $this->assertSame([$connection->getKey()], $vault->connections()->pluck('connections.id')->all());
    }

    public function test_a_vault_cannot_include_someone_elses_connection(): void
    {
        $theirs = Connection::factory()->create();

        Livewire::test(CreateVault::class)
            ->fillForm(['name' => 'Work', 'connections' => [$theirs->getKey()]])
            ->call('create')
            ->assertHasFormErrors(['connections']);

        $this->assertDatabaseCount('vaults', 0);
    }

    public function test_account_limits_are_enforced(): void
    {
        config(['nexus.limits.vaults_per_user' => 1]);
        Vault::factory()->for($this->user)->create();

        $this->get(VaultResource::getUrl('create'))->assertForbidden();
    }

    public function test_every_page_renders(): void
    {
        $connection = Connection::factory()->for($this->user)->withHeader()->create();
        ConnectionTool::factory()->for($connection)->readOnly()->create();
        $vault = Vault::factory()->for($this->user)->create();
        $vault->connections()->attach($connection);
        VaultToken::issue($vault, 'Codex');

        $this->get(ConnectionResource::getUrl('index'))->assertOk();
        $this->get(ConnectionResource::getUrl('create'))->assertOk();
        $this->get(ConnectionResource::getUrl('edit', ['record' => $connection]))->assertOk()->assertSee($connection->name);
        $this->get(VaultResource::getUrl('index'))->assertOk()->assertSee($vault->endpointUrl());
        $this->get(VaultResource::getUrl('edit', ['record' => $vault]))->assertOk();
        $this->get(VaultResource::getUrl('tools', ['record' => $vault]))->assertOk();
        $this->get(ToolCallLogResource::getUrl('index'))->assertOk();
    }

    public function test_setup_instructions_read_the_token_from_an_environment_variable(): void
    {
        $vault = Vault::factory()->for($this->user)->create(['name' => 'Work']);

        Livewire::test(EditVault::class, ['record' => $vault->getKey()])
            ->mountAction('setup')
            ->assertMountedActionModalSee([
                'export NEXUS_WORK_TOKEN=nxs_…',
                'bearer_token_env_var = "NEXUS_WORK_TOKEN"',
                'Bearer ${env:NEXUS_WORK_TOKEN}',
                $vault->endpointUrl(),
            ]);
    }

    public function test_changing_the_server_url_discards_tokens_issued_for_the_old_one(): void
    {
        $connection = Connection::factory()->for($this->user)->withOAuthTokens('old-access', 'old-refresh')->create([
            'account_identity' => 'ada@example.com',
        ]);

        Livewire::test(EditConnection::class, ['record' => $connection->getKey()])
            ->fillForm(['url' => 'https://elsewhere.example.com/mcp'])
            ->call('save')
            ->assertHasNoFormErrors();

        $connection = Connection::query()->find($connection->getKey());
        $this->assertSame(ConnectionStatus::Pending, $connection->status);
        $this->assertNull($connection->secret('access_token'));
        $this->assertNull($connection->secret('refresh_token'));
        $this->assertNull($connection->secret('client_id'));
        $this->assertNull($connection->account_identity);
    }

    public function test_renaming_a_connection_keeps_its_credentials(): void
    {
        $connection = Connection::factory()->for($this->user)->withHeader('Bearer keep-me')->create();

        Livewire::test(EditConnection::class, ['record' => $connection->getKey()])
            ->fillForm(['name' => 'Renamed', 'header_value' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $connection = Connection::query()->find($connection->getKey());
        $this->assertSame('Renamed', $connection->name);
        $this->assertSame('Bearer keep-me', $connection->secret('header_value'));
        $this->assertSame(ConnectionStatus::Active, $connection->status);
    }

    public function test_refreshing_warns_when_two_connections_are_the_same_account(): void
    {
        Connection::factory()->for($this->user)->create(['name' => 'Slack (Work)', 'url' => 'https://svc.example.com/mcp', 'account_identity' => 'ada@example.com']);
        $second = Connection::factory()->for($this->user)->create(['name' => 'Slack (Personal)', 'url' => 'https://svc.example.com/mcp']);

        $server = (new FakeMcpServer)->fake();
        $server->toolsJson = '[{"name":"whoami","inputSchema":{"type":"object"}}]';
        $server->onCall = fn (): string => '{"content":[],"structuredContent":{"email":"ada@example.com"}}';

        ConnectionResource::refreshTools($second);

        Notification::assertNotified('Same account as Slack (Work)');
    }

    public function test_tool_tables_show_declared_hints_apart_from_defaults(): void
    {
        $connection = Connection::factory()->for($this->user)->create();
        $declared = ConnectionTool::factory()->for($connection)->create(['name' => 'declared', 'idempotent' => true, 'open_world' => false]);
        $silent = ConnectionTool::factory()->for($connection)->create(['name' => 'silent', 'idempotent' => null, 'open_world' => null]);
        $vault = Vault::factory()->for($this->user)->create();
        $vault->connections()->attach($connection);

        Livewire::test(ManageVaultTools::class, ['record' => $vault->getKey()])
            ->assertTableColumnStateSet('idempotent', 'yes', $declared)
            ->assertTableColumnStateSet('open_world', 'no', $declared)
            ->assertTableColumnStateSet('idempotent', 'unsaid', $silent)
            ->assertTableColumnStateSet('open_world', 'unsaid', $silent);
    }
}
