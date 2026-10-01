<?php

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Mcp\Downstream\ConnectionCatalog;
use App\Mcp\Downstream\ConnectionNeedsAuth;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Vault;
use App\Models\VaultTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeMcpServer;
use Tests\TestCase;

class ConnectionCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_caches_tool_definitions_exactly_as_sent(): void
    {
        $connection = Connection::factory()->create(['url' => 'https://svc.example.com/mcp']);

        $server = (new FakeMcpServer)->fake();
        $server->toolsJson = '[{"name":"search","description":"Find things.","inputSchema":{"type":"object","properties":{}},"annotations":{"readOnlyHint":true}},'
            .'{"name":"delete_all","inputSchema":{"type":"object"},"annotations":{"destructiveHint":true}},'
            .'{"name":"archive","inputSchema":{"type":"object"},"annotations":{"destructiveHint":false}}]';

        $count = app(ConnectionCatalog::class)->refresh($connection);

        $this->assertSame(3, $count);

        $tools = $connection->tools()->get()->keyBy('name');
        $this->assertTrue($tools['search']->read_only);
        $this->assertFalse($tools['search']->destructive);
        $this->assertTrue($tools['delete_all']->destructive);
        $this->assertFalse($tools['archive']->destructive);
        $this->assertStringContainsString('"properties":{}', $tools['search']->definition);

        $connection->refresh();
        $this->assertSame(ConnectionStatus::Active, $connection->status);
        $this->assertNotNull($connection->tools_refreshed_at);
        $this->assertSame('2025-11-25', $connection->protocol_version);
    }

    public function test_tools_that_disappear_are_removed_with_their_vault_switches(): void
    {
        $connection = Connection::factory()->create(['url' => 'https://svc.example.com/mcp']);
        $gone = ConnectionTool::factory()->for($connection)->create(['name' => 'old_tool']);
        $vault = Vault::factory()->for($connection->user)->create();
        VaultTool::query()->create(['vault_id' => $vault->id, 'connection_id' => $connection->id, 'tool_name' => 'old_tool', 'enabled' => true]);

        $server = (new FakeMcpServer)->fake();
        $server->toolsJson = '[{"name":"new_tool","inputSchema":{"type":"object"}}]';

        app(ConnectionCatalog::class)->refresh($connection);

        $this->assertModelMissing($gone);
        $this->assertSame(['new_tool'], $connection->tools()->pluck('name')->all());
        $this->assertSame(0, VaultTool::query()->count());
    }

    public function test_tools_with_names_the_spec_disallows_are_skipped(): void
    {
        $connection = Connection::factory()->create(['url' => 'https://svc.example.com/mcp']);

        $server = (new FakeMcpServer)->fake();
        $server->toolsJson = '[{"name":"ok_tool","inputSchema":{"type":"object"}},{"name":"bad tool!","inputSchema":{"type":"object"}}]';

        $this->assertSame(1, app(ConnectionCatalog::class)->refresh($connection));
    }

    public function test_servers_on_the_newest_protocol_are_supported(): void
    {
        $connection = Connection::factory()->create(['url' => 'https://svc.example.com/mcp']);

        $server = (new FakeMcpServer)->fake();
        $server->modern = true;
        $server->toolsJson = '[{"name":"search","inputSchema":{"type":"object"}}]';

        app(ConnectionCatalog::class)->refresh($connection);

        $this->assertSame('2026-07-28', $connection->fresh()->protocol_version);
    }

    public function test_servers_still_on_2025_03_26_are_supported(): void
    {
        $connection = Connection::factory()->create(['url' => 'https://svc.example.com/mcp']);

        $server = (new FakeMcpServer)->fake();
        $server->initializeVersion = '2025-03-26';
        $server->toolsJson = '[{"name":"search","inputSchema":{"type":"object"}}]';

        $this->assertSame(1, app(ConnectionCatalog::class)->refresh($connection));
        $this->assertSame('2025-03-26', $connection->fresh()->protocol_version);
    }

    public function test_a_rejected_api_key_marks_the_connection_as_needing_sign_in(): void
    {
        $connection = Connection::factory()->withHeader('Bearer wrong')->create(['url' => 'https://svc.example.com/mcp']);

        $server = (new FakeMcpServer)->fake();
        $server->requireAuthorization = 'Bearer right';

        try {
            app(ConnectionCatalog::class)->refresh($connection);
            $this->fail('Expected the refresh to require sign-in.');
        } catch (ConnectionNeedsAuth) {
            //
        }

        $this->assertSame(ConnectionStatus::NeedsAuth, $connection->fresh()->status);
    }

    public function test_the_custom_header_is_sent_downstream(): void
    {
        $connection = Connection::factory()->withHeader('key-123', 'X-Api-Key')->create(['url' => 'https://svc.example.com/mcp']);

        $server = (new FakeMcpServer)->fake();

        app(ConnectionCatalog::class)->refresh($connection);

        $this->assertSame(['key-123'], $server->headers[0]['X-Api-Key']);
        $this->assertArrayNotHasKey('Authorization', $server->headers[0]);
    }
}
