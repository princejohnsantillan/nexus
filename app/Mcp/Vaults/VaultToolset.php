<?php

namespace App\Mcp\Vaults;

use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Vault;
use App\Models\VaultTool;
use Illuminate\Support\Collection;

/**
 * Works out which tools a vault exposes.
 *
 * A tool is on when the vault has an explicit switch turned on for it, or,
 * without a switch, when the server marks it read-only. New tools from a
 * downstream server follow the same rule, so writes never appear in a vault
 * until the owner turns them on.
 */
class VaultToolset
{
    /** The longest tool name the MCP spec allows. */
    protected const MAX_NAME_LENGTH = 128;

    /**
     * @return Collection<int, ExposedTool>
     */
    public function all(Vault $vault): Collection
    {
        $connections = $vault->connections()
            ->where('connections.user_id', $vault->user_id)
            ->with('tools')
            ->get();

        $switches = $vault->toolOverrides()->get()
            ->keyBy(fn (VaultTool $switch): string => $switch->connection_id.'|'.$switch->tool_name);

        return $connections
            ->flatMap(fn (Connection $connection): Collection => $connection->tools->map(
                fn (ConnectionTool $tool): ExposedTool => new ExposedTool(
                    $connection,
                    $tool,
                    $switches->get($connection->id.'|'.$tool->name)?->enabled ?? static::enabledByDefault($tool),
                ),
            ))
            ->filter(fn (ExposedTool $tool): bool => strlen($tool->name()) <= self::MAX_NAME_LENGTH)
            ->values();
    }

    /**
     * @return Collection<int, ExposedTool>
     */
    public function enabled(Vault $vault): Collection
    {
        return $this->all($vault)->filter(fn (ExposedTool $tool): bool => $tool->enabled)->values();
    }

    public function find(Vault $vault, string $name): ?ExposedTool
    {
        return $this->enabled($vault)->first(fn (ExposedTool $tool): bool => $tool->name() === $name);
    }

    public function isEnabled(Vault $vault, ConnectionTool $tool): bool
    {
        $switch = $vault->toolOverrides()
            ->where('connection_id', $tool->connection_id)
            ->where('tool_name', $tool->name)
            ->first();

        return $switch?->enabled ?? static::enabledByDefault($tool);
    }

    public function setEnabled(Vault $vault, ConnectionTool $tool, bool $enabled): void
    {
        $vault->toolOverrides()->updateOrCreate(
            ['connection_id' => $tool->connection_id, 'tool_name' => $tool->name],
            ['enabled' => $enabled],
        );
    }

    public function resetToDefault(Vault $vault, ConnectionTool $tool): void
    {
        $vault->toolOverrides()
            ->where('connection_id', $tool->connection_id)
            ->where('tool_name', $tool->name)
            ->delete();
    }

    public static function enabledByDefault(ConnectionTool $tool): bool
    {
        return $tool->read_only;
    }
}
