<?php

namespace App\Mcp\Vaults;

use App\Models\Connection;
use App\Models\ConnectionTool;
use Illuminate\Support\Str;

/**
 * A downstream tool as one vault presents it: renamed with the connection's
 * handle, plus whether the vault has it switched on.
 *
 * When the vault holds more than one account of the same service
 * ("siblings"), the description also says what this account is for, so the
 * agent can pick between slack-bw__search_messages and
 * slack-me__search_messages.
 */
final class ExposedTool
{
    public const SEPARATOR = '__';

    /** How much of the owner's "use for" note goes into each tool description. */
    protected const USE_FOR_LIMIT = 120;

    public function __construct(
        public readonly Connection $connection,
        public readonly ConnectionTool $tool,
        public readonly bool $enabled,
        public readonly bool $hasSiblings = false,
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
            'description' => trim("[{$this->accountLabel()}] ".(is_string($definition->description ?? null) ? $definition->description : '')),
            'inputSchema' => $definition->inputSchema ?? (object) ['type' => 'object'],
        ];

        foreach (['title', 'outputSchema', 'annotations', 'icons'] as $key) {
            if (isset($definition->{$key})) {
                $entry[$key] = $definition->{$key};
            }
        }

        return $entry;
    }

    /**
     * Leads every description, where clients that truncate long
     * descriptions are sure to keep it.
     */
    protected function accountLabel(): string
    {
        return self::labelFor($this->connection, $this->hasSiblings);
    }

    /**
     * The account label that leads tool and prompt descriptions: the
     * account, plus what it's for when the vault has several of its service.
     */
    public static function labelFor(Connection $connection, bool $hasSiblings): string
    {
        $label = $connection->accountSummary();

        if ($hasSiblings && filled($connection->description)) {
            $label .= ' — use for: '.Str::limit($connection->description, self::USE_FOR_LIMIT);
        }

        return $label;
    }
}
