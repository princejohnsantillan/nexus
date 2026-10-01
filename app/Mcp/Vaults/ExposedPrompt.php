<?php

namespace App\Mcp\Vaults;

use App\Models\Connection;
use App\Models\ConnectionPrompt;

/**
 * A downstream prompt as one vault presents it: renamed with the
 * connection's handle, like tools are.
 */
final class ExposedPrompt
{
    public function __construct(
        public readonly Connection $connection,
        public readonly ConnectionPrompt $prompt,
        public readonly bool $enabled,
        public readonly bool $hasSiblings = false,
    ) {}

    public static function nameFor(Connection $connection, ConnectionPrompt $prompt): string
    {
        return $connection->handle.ExposedTool::SEPARATOR.$prompt->name;
    }

    public function name(): string
    {
        return self::nameFor($this->connection, $this->prompt);
    }

    /**
     * The prompts/list entry; arguments are passed through as the server sent them.
     *
     * @return array<string, mixed>
     */
    public function toListEntry(): array
    {
        $definition = $this->prompt->definitionObject();

        $entry = [
            'name' => $this->name(),
            'description' => trim('['.ExposedTool::labelFor($this->connection, $this->hasSiblings).'] '.(is_string($definition->description ?? null) ? $definition->description : '')),
        ];

        foreach (['title', 'arguments', 'icons'] as $key) {
            if (isset($definition->{$key})) {
                $entry[$key] = $definition->{$key};
            }
        }

        return $entry;
    }
}
