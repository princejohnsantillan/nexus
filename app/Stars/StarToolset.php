<?php

declare(strict_types=1);

namespace App\Stars;

use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A Star's tools as clients see them.
 *
 * Every tool in the catalog of each Connection the Star includes is exposed
 * as `{handle}__{tool}`. It is on when the user switched it on in this Star,
 * off when they switched it off, and otherwise as the Star's new-tool policy
 * says for the tool's current annotations, so a tool that stops being
 * read-only on its server stops being on under the read-only policy.
 * Switches are kept by tool name, so they survive catalog refreshes.
 */
class StarToolset
{
    /**
     * What joins a Connection's handle to its tool's name. Handles never
     * contain an underscore, so the first separator ends the handle.
     */
    public const string SEPARATOR = '__';

    /**
     * Every tool of the Star's Connections, on or off: Connections by name,
     * then each one's tools by name.
     *
     * @return list<StarTool>
     */
    public function tools(Star $star): array
    {
        $connections = $star->connections()
            ->with(['tools' => fn (Relation $query): Relation => $query->orderBy('name')])
            ->orderBy('name')
            ->orderBy('connections.id')
            ->get();

        $switches = [];

        foreach ($star->toolSwitches()->get(['connection_id', 'tool_name', 'enabled']) as $switch) {
            $switches[$switch->connection_id][$switch->tool_name] = $switch->enabled;
        }

        $tools = [];

        foreach ($connections as $connection) {
            foreach ($connection->tools as $tool) {
                $tools[] = $this->expose($star, $connection, $tool, $switches[$connection->id][$tool->name] ?? null);
            }
        }

        return $tools;
    }

    /**
     * The tools of the Star's Connections that are on, in the same order as tools().
     *
     * @return list<StarTool>
     */
    public function enabledTools(Star $star): array
    {
        return array_values(array_filter($this->tools($star), fn (StarTool $tool): bool => $tool->enabled));
    }

    /**
     * The tool clients call by this exposed name, or null when none of the
     * Star's Connections has it or it is off.
     */
    public function enabledTool(Star $star, string $name): ?StarTool
    {
        $tool = $this->tool($star, $name);

        return $tool?->enabled === true ? $tool : null;
    }

    /**
     * The tool with this exposed name, on or off, or null when none of the
     * Star's Connections has it.
     */
    public function tool(Star $star, string $name): ?StarTool
    {
        if (! str_contains($name, self::SEPARATOR)) {
            return null;
        }

        [$handle, $toolName] = explode(self::SEPARATOR, $name, 2);

        $connection = $star->connections()->where('handle', $handle)->first();
        $tool = $connection?->tools()->where('name', $toolName)->first();

        if ($connection === null || $tool === null) {
            return null;
        }

        $switch = $star->toolSwitches()->where('connection_id', $connection->id)->where('tool_name', $toolName)->first();

        return $this->expose($star, $connection, $tool, $switch?->enabled);
    }

    private function expose(Star $star, Connection $connection, ConnectionTool $tool, ?bool $switch): StarTool
    {
        return new StarTool(
            connection: $connection,
            tool: $tool,
            name: $connection->handle.self::SEPARATOR.$tool->name,
            enabled: $switch ?? $star->new_tool_policy->enables($tool),
            switch: $switch,
        );
    }
}
