<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Enums\ToolCallStatus;
use App\Mcp\Vaults\VaultInstructions;
use App\Mcp\Vaults\VaultToolset;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\ToolCallLog;
use App\Models\Vault;
use App\Models\VaultToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeMcpServer;
use Tests\TestCase;

class VaultEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected Vault $vault;

    protected Connection $connection;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = Connection::factory()->withHeader('Bearer downstream-key')->create([
            'name' => 'Slack',
            'handle' => 'slack',
            'url' => 'https://svc.example.com/mcp',
        ]);

        $this->vault = Vault::factory()->for($this->connection->user)->create(['name' => 'Work']);
        $this->vault->connections()->attach($this->connection);

        [, $this->token] = VaultToken::issue($this->vault, 'Claude Code');
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->rpc('tools/list', token: null)->assertUnauthorized();
    }

    public function test_a_token_only_opens_its_own_vault(): void
    {
        $otherVault = Vault::factory()->for($this->connection->user)->create();

        $this->rpc('tools/list', vault: $otherVault)->assertUnauthorized();
    }

    public function test_revoked_and_expired_tokens_are_rejected(): void
    {
        [$revoked, $revokedPlain] = VaultToken::issue($this->vault, 'Old laptop');
        $revoked->revoke();

        [, $expiredPlain] = VaultToken::issue($this->vault, 'Trial', now()->subMinute());

        $this->rpc('tools/list', token: $revokedPlain)->assertUnauthorized();
        $this->rpc('tools/list', token: $expiredPlain)->assertUnauthorized();
    }

    public function test_legacy_clients_can_initialize(): void
    {
        $this->rpc('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'claude-code', 'version' => '2.1'],
        ])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-11-25')
            ->assertJsonPath('result.serverInfo.name', 'Nexus: Work')
            ->assertJsonPath('result.capabilities.tools.listChanged', false)
            ->assertJsonMissingPath('result.capabilities.prompts');
    }

    public function test_tools_are_listed_under_the_connection_handle_with_schemas_untouched(): void
    {
        $this->tool('search_messages', readOnly: true, schema: '{"type":"object","properties":{},"additionalProperties":{}}');

        $response = $this->rpc('tools/list')->assertOk();

        $response->assertJsonPath('result.tools.0.name', 'slack__search_messages');
        $response->assertJsonPath('result.tools.0.description', '[Slack] Searches.');
        $this->assertStringContainsString('"inputSchema":{"type":"object","properties":{},"additionalProperties":{}}', $response->getContent());
    }

    public function test_read_only_tools_are_on_by_default_and_writes_are_off(): void
    {
        $this->tool('search_messages', readOnly: true);
        $this->tool('send_message', readOnly: false);

        $this->assertSame(['slack__search_messages'], $this->listedToolNames());
    }

    public function test_switches_override_the_default(): void
    {
        $search = $this->tool('search_messages', readOnly: true);
        $send = $this->tool('send_message', readOnly: false);

        app(VaultToolset::class)->setEnabled($this->vault, $send, true);
        app(VaultToolset::class)->setEnabled($this->vault, $search, false);

        $this->assertSame(['slack__send_message'], $this->listedToolNames());
    }

    public function test_connections_owned_by_someone_else_are_never_exposed(): void
    {
        $foreign = Connection::factory()->create(['handle' => 'foreign']);
        ConnectionTool::factory()->for($foreign)->readOnly()->create(['name' => 'read_secrets']);
        $this->vault->connections()->attach($foreign);

        $this->assertSame([], $this->listedToolNames());
    }

    public function test_modern_clients_can_list_tools(): void
    {
        $this->tool('search_messages', readOnly: true);

        $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/list',
        ])->postJson("/mcp/{$this->vault->public_id}", [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => (object) [],
            ]],
        ])->assertOk()->assertJsonPath('result.tools.0.name', 'slack__search_messages');
    }

    public function test_tool_calls_are_forwarded_with_the_connection_credentials_and_passed_through(): void
    {
        $this->tool('search_messages', readOnly: true);

        $server = (new FakeMcpServer)->fake();
        $server->requireAuthorization = 'Bearer downstream-key';
        $server->onCall = fn (): string => '{"content":[{"type":"text","text":"3 messages"}],"structuredContent":{"matches":3,"filters":{}},"isError":false}';

        $response = $this->rawRpc('{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"slack__search_messages","arguments":{"query":"deploy","filters":{}}}}');

        $response->assertOk()
            ->assertJsonPath('id', 7)
            ->assertJsonPath('result.content.0.text', '3 messages')
            ->assertJsonPath('result.isError', false);
        $this->assertStringContainsString('"structuredContent":{"matches":3,"filters":{}}', $response->getContent());

        $call = $server->receivedCalls()[0];
        $this->assertSame('search_messages', $call->params->name);
        $this->assertSame('{"query":"deploy","filters":{}}', json_encode($call->params->arguments));
    }

    public function test_each_call_is_logged_without_its_arguments_or_result(): void
    {
        $this->tool('search_messages', readOnly: true);
        (new FakeMcpServer)->fake();

        $this->rpc('tools/call', ['name' => 'slack__search_messages', 'arguments' => ['query' => 'payroll']])->assertOk();

        $log = ToolCallLog::query()->sole();
        $this->assertSame('slack__search_messages', $log->tool_name);
        $this->assertSame(ToolCallStatus::Ok, $log->status);
        $this->assertSame($this->vault->id, $log->vault_id);
        $this->assertStringNotContainsString('payroll', json_encode($log->getAttributes()));
    }

    public function test_switched_off_tools_cannot_be_called(): void
    {
        $this->tool('send_message', readOnly: false);
        $server = (new FakeMcpServer)->fake();

        $this->rpc('tools/call', ['name' => 'slack__send_message', 'arguments' => ['text' => 'hi']])
            ->assertJsonPath('error.code', -32602);

        $this->assertSame([], $server->receivedCalls());
    }

    public function test_rejected_credentials_ask_the_user_to_reconnect(): void
    {
        $this->tool('search_messages', readOnly: true);

        $server = (new FakeMcpServer)->fake();
        $server->requireAuthorization = 'Bearer rotated-key';

        $this->rpc('tools/call', ['name' => 'slack__search_messages', 'arguments' => []])
            ->assertOk()
            ->assertJsonPath('result.isError', true);

        $this->assertSame(ConnectionStatus::NeedsAuth, $this->connection->fresh()->status);
        $this->assertSame(ToolCallStatus::AuthRequired, ToolCallLog::query()->sole()->status);
    }

    public function test_an_expired_oauth_token_is_refreshed_before_the_call(): void
    {
        $connection = Connection::factory()->for($this->vault->user)->withOAuthTokens('stale-access', 'refresh-1', expiresAt: time() - 10)->create([
            'handle' => 'linear',
            'url' => 'https://svc.example.com/mcp',
        ]);
        $this->vault->connections()->attach($connection);
        ConnectionTool::factory()->for($connection)->readOnly()->create(['name' => 'list_issues']);

        $server = new FakeMcpServer;
        $server->requireAuthorization = 'Bearer fresh-access';
        $this->fakeAuthorizationServer(['https://svc.example.com/mcp' => $server]);

        $this->rpc('tools/call', ['name' => 'linear__list_issues', 'arguments' => []])
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        $connection->refresh();
        $this->assertSame('fresh-access', $connection->secret('access_token'));
        $this->assertSame('refresh-2', $connection->secret('refresh_token'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.example.com/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-1');
    }

    public function test_a_single_account_keeps_its_description_short(): void
    {
        $this->connection->forceFill(['description' => 'Work workspace', 'account_identity' => 'ada@bw.org'])->save();
        $this->tool('search_messages', readOnly: true);

        $this->rpc('tools/list')->assertJsonPath('result.tools.0.description', '[Slack · ada@bw.org] Searches.');
    }

    public function test_sibling_accounts_say_what_each_is_for(): void
    {
        $this->connection->forceFill(['description' => 'BetterWorld work: deploys and teammates', 'account_identity' => 'ada@bw.org'])->save();
        $this->tool('search_messages', readOnly: true);
        $this->personalSlack();

        $descriptions = collect($this->rpc('tools/list')->json('result.tools'))->pluck('description', 'name');

        $this->assertSame('[Slack · ada@bw.org — use for: BetterWorld work: deploys and teammates] Searches.', $descriptions['slack__search_messages']);
        $this->assertSame('[Slack (Personal) · ada@home.example — use for: Family and side projects] Searches.', $descriptions['slack-me__search_messages']);
    }

    public function test_the_instructions_explain_how_to_choose_between_accounts(): void
    {
        $this->personalSlack();

        $instructions = $this->rpc('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'claude-code', 'version' => '2.1'],
        ])->json('result.instructions');

        $this->assertStringContainsString('- slack-me: Slack (Personal) · ada@home.example. Use for: Family and side projects', $instructions);
        $this->assertStringContainsString('more than one account here (slack, slack-me)', $instructions);
        $this->assertStringContainsString('ask the user which account to use', $instructions);
    }

    public function test_the_instructions_stay_within_what_clients_keep(): void
    {
        foreach (range(1, 12) as $index) {
            $connection = Connection::factory()->for($this->vault->user)->create([
                'url' => 'https://svc.example.com/mcp',
                'description' => str_repeat("Account {$index} notes. ", 40),
            ]);
            $this->vault->connections()->attach($connection);
        }

        $instructions = app(VaultInstructions::class)->for($this->vault);

        $this->assertLessThanOrEqual(VaultInstructions::MAX_LENGTH, mb_strlen($instructions));
        $this->assertStringContainsString('ask the user which account to use', $instructions);
    }

    public function test_results_from_sibling_accounts_say_which_account_answered(): void
    {
        $this->tool('search_messages', readOnly: true);
        $this->personalSlack();

        $server = (new FakeMcpServer)->fake();
        $server->onCall = fn (): string => '{"content":[{"type":"text","text":"0 messages"}],"isError":false}';

        $this->rpc('tools/call', ['name' => 'slack-me__search_messages', 'arguments' => ['query' => 'invoice']])
            ->assertJsonPath('result.content.0.text', 'From Slack (Personal) · ada@home.example (slack-me).')
            ->assertJsonPath('result.content.1.text', '0 messages');
    }

    public function test_results_from_a_single_account_are_passed_through_untouched(): void
    {
        $this->tool('search_messages', readOnly: true);
        (new FakeMcpServer)->fake();

        $this->rpc('tools/call', ['name' => 'slack__search_messages', 'arguments' => []])
            ->assertJsonCount(1, 'result.content')
            ->assertJsonPath('result.content.0.text', 'ok');
    }

    /**
     * A second Slack account on the same server, in the same vault.
     */
    protected function personalSlack(): Connection
    {
        $connection = Connection::factory()->for($this->vault->user)->create([
            'name' => 'Slack (Personal)',
            'handle' => 'slack-me',
            'url' => 'https://svc.example.com/mcp',
            'description' => 'Family and side projects',
            'account_identity' => 'ada@home.example',
        ]);
        $this->vault->connections()->attach($connection);

        $definition = '{"name":"search_messages","description":"Searches.","inputSchema":{"type":"object","properties":{}},"annotations":{"readOnlyHint":true}}';
        ConnectionTool::factory()->for($connection)->create([
            'name' => 'search_messages',
            'definition' => $definition,
            'definition_hash' => hash('sha256', $definition),
            'read_only' => true,
            'destructive' => false,
        ]);

        return $connection;
    }

    protected function tool(string $name, bool $readOnly, string $schema = '{"type":"object","properties":{}}'): ConnectionTool
    {
        $definition = '{"name":"'.$name.'","description":"'.($readOnly ? 'Searches.' : 'Sends.').'","inputSchema":'.$schema.',"annotations":{"readOnlyHint":'.($readOnly ? 'true' : 'false').'}}';

        return ConnectionTool::factory()->for($this->connection)->create([
            'name' => $name,
            'definition' => $definition,
            'definition_hash' => hash('sha256', $definition),
            'read_only' => $readOnly,
            'destructive' => ! $readOnly,
        ]);
    }

    /**
     * @return list<string>
     */
    protected function listedToolNames(): array
    {
        return array_column($this->rpc('tools/list')->assertOk()->json('result.tools'), 'name');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function rpc(string $method, array $params = [], ?Vault $vault = null, ?string $token = 'default'): TestResponse
    {
        $token = $token === 'default' ? $this->token : $token;

        return $this->withHeaders(array_filter([
            'Authorization' => $token === null ? null : "Bearer {$token}",
            'Accept' => 'application/json, text/event-stream',
        ]))->postJson('/mcp/'.($vault ?? $this->vault)->public_id, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ]);
    }

    protected function rawRpc(string $body): TestResponse
    {
        return $this->call('POST', "/mcp/{$this->vault->public_id}", server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => "Bearer {$this->token}",
        ], content: $body);
    }

    /**
     * @param  array<string, FakeMcpServer>  $servers
     */
    protected function fakeAuthorizationServer(array $servers): void
    {
        Http::fake([
            ...array_map(fn (FakeMcpServer $server) => $server->handler(), $servers),
            'https://svc.example.com/.well-known/oauth-protected-resource/mcp' => Http::response([
                'resource' => 'https://svc.example.com/mcp',
                'authorization_servers' => ['https://auth.example.com'],
            ]),
            'https://auth.example.com/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.example.com',
                'authorization_endpoint' => 'https://auth.example.com/authorize',
                'token_endpoint' => 'https://auth.example.com/token',
                'code_challenge_methods_supported' => ['S256'],
            ]),
            'https://auth.example.com/token' => Http::response([
                'access_token' => 'fresh-access',
                'refresh_token' => 'refresh-2',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ]);
    }
}
