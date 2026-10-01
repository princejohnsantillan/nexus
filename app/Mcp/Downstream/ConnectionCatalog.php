<?php

namespace App\Mcp\Downstream;

use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\VaultTool;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Keeps each connection's cached copy of its server's tool list.
 *
 * Vaults are served from this cache, never from a live tools/list, so a
 * slow or broken downstream server can't stall every vault that uses it.
 */
class ConnectionCatalog
{
    /** Tool names the MCP spec allows. */
    protected const NAME_PATTERN = '/^[A-Za-z0-9_.-]{1,128}$/';

    public function __construct(protected DownstreamClients $clients) {}

    /**
     * @return int The number of tools now cached.
     *
     * @throws ConnectionNeedsAuth
     * @throws Throwable when the server can't be reached or answers badly.
     */
    public function refresh(Connection $connection): int
    {
        try {
            $session = $this->clients->open($connection);
            $tools = $session->listTools();
        } catch (ConnectionNeedsAuth $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $connection->markStatus(ConnectionStatus::Error, $exception->getMessage());

            throw $exception;
        }

        $tools = array_values(array_filter(
            $tools,
            fn (stdClass $tool): bool => preg_match(self::NAME_PATTERN, $tool->name) === 1,
        ));

        DB::transaction(function () use ($connection, $tools): void {
            $names = [];

            foreach ($tools as $tool) {
                $names[] = $tool->name;

                $definition = (string) json_encode($tool, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $readOnly = ($tool->annotations->readOnlyHint ?? false) === true;

                $connection->tools()->updateOrCreate(['name' => $tool->name], [
                    'title' => is_string($tool->title ?? null) ? $tool->title : (is_string($tool->annotations->title ?? null) ? $tool->annotations->title : null),
                    'description' => is_string($tool->description ?? null) ? $tool->description : null,
                    'definition' => $definition,
                    'definition_hash' => hash('sha256', $definition),
                    'read_only' => $readOnly,
                    // Per the spec, a tool that isn't read-only is assumed destructive unless it says otherwise.
                    'destructive' => ! $readOnly && ($tool->annotations->destructiveHint ?? true) !== false,
                    // Kept as declared (null when unsaid), so the UI can tell a claim from a default.
                    'idempotent' => is_bool($tool->annotations->idempotentHint ?? null) ? $tool->annotations->idempotentHint : null,
                    'open_world' => is_bool($tool->annotations->openWorldHint ?? null) ? $tool->annotations->openWorldHint : null,
                ]);
            }

            $connection->tools()->whereNotIn('name', $names)->delete();

            VaultTool::query()
                ->where('connection_id', $connection->id)
                ->whereNotIn('tool_name', $names)
                ->delete();

            $connection->forceFill([
                'status' => ConnectionStatus::Active,
                'status_message' => null,
                'tools_refreshed_at' => now(),
            ])->save();
        });

        $this->detectIdentity($connection, $session, $tools);

        return count($tools);
    }

    /**
     * If the server has a profile tool ("whoami", "get_me"…), ask it who
     * this connection is signed in as. Nexus calls it for the owner's label
     * even when no vault has it switched on; it takes no arguments and
     * changes nothing. A failure here never fails the refresh.
     *
     * @param  list<stdClass>  $tools
     */
    protected function detectIdentity(Connection $connection, DownstreamSession $session, array $tools): void
    {
        $profileTool = AccountIdentity::profileTool($tools);

        if ($profileTool === null) {
            return;
        }

        try {
            $identity = AccountIdentity::fromToolResult($session->callTool($profileTool, []));
        } catch (Throwable) {
            return;
        }

        if ($identity !== null && $identity !== $connection->account_identity) {
            $connection->forceFill(['account_identity' => $identity])->save();
        }
    }
}
