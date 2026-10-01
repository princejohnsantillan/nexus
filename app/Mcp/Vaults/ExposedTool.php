<?php

namespace App\Mcp\Vaults;

use App\Models\Connection;
use App\Models\ConnectionTool;

/**
 * A downstream tool as one vault presents it: renamed with the connection's
 * handle, plus whether the vault has it switched on.
 */
final class ExposedTool
{
    public const SEPARATOR = '__';

    public function __construct(
        public readonly Connection $connection,
        public readonly ConnectionTool $tool,
        public readonly bool $enabled,
    ) {}

    public static function nameFor(Connection $connection, ConnectionTool $tool): string
    {
        return $connection->handle.self::SEPARATOR.$tool->name;
    }

    public function name(): string
    {
        return self::nameFor($this->connection, $this->tool);
    }

    /**
     * The tools/list entry. Schemas and annotations are passed through as the
     * downstream server sent them.
     *
     * @return array<string, mixed>
     */
    public function toListEntry(): array
    {
        $definition = $this->tool->definitionObject();

        $entry = [
            'name' => $this->name(),
            'description' => trim("[{$this->connection->name}] ".(is_string($definition->description ?? null) ? $definition->description : '')),
            'inputSchema' => $definition->inputSchema ?? (object) ['type' => 'object'],
        ];

        foreach (['title', 'outputSchema', 'annotations', 'icons'] as $key) {
            if (isset($definition->{$key})) {
                $entry[$key] = $definition->{$key};
            }
        }

        return $entry;
    }
}
