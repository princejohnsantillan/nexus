<?php

declare(strict_types=1);

namespace App\Stars;

use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\Star;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A Star's prompts as clients see them.
 *
 * Every prompt in the catalog of each Connection the Star includes is
 * exposed as `{handle}__{prompt}`, named like the tools. Unlike tools, a
 * prompt is on unless the user switched it off in this Star: it only
 * returns messages for the agent, and any tool those lead to is still
 * governed by its own switch. Switches are kept by prompt name, so they
 * survive catalog refreshes.
 *
 * Connections of the same service in the Star (siblings) are told apart by
 * their account labels, which lead their prompts' descriptions.
 */
class StarPrompts
{
    /**
     * Every prompt of the Star's Connections, on or off: Connections by
     * name, then each one's prompts by name.
     *
     * @return list<StarPrompt>
     */
    public function prompts(Star $star): array
    {
        $connections = $star->connections()
            ->with(['prompts' => fn (Relation $query): Relation => $query->orderBy('name')])
            ->orderBy('name')
            ->orderBy('connections.id')
            ->get();

        $switches = [];

        foreach ($star->promptSwitches()->get(['connection_id', 'prompt_name', 'enabled']) as $switch) {
            $switches[$switch->connection_id][$switch->prompt_name] = $switch->enabled;
        }

        $siblingIds = Connection::idsWithSiblings($connections);
        $prompts = [];

        foreach ($connections as $connection) {
            foreach ($connection->prompts as $prompt) {
                $prompts[] = $this->expose($connection, $prompt, $switches[$connection->id][$prompt->name] ?? null, $siblingIds);
            }
        }

        return $prompts;
    }

    /**
     * The prompts of the Star's Connections that are on, in the same order as prompts().
     *
     * @return list<StarPrompt>
     */
    public function enabledPrompts(Star $star): array
    {
        return array_values(array_filter($this->prompts($star), fn (StarPrompt $prompt): bool => $prompt->enabled));
    }

    /**
     * The prompt with this exposed name, on or off, or null when none of the
     * Star's Connections has it.
     */
    public function prompt(Star $star, string $name): ?StarPrompt
    {
        if (! str_contains($name, StarToolset::SEPARATOR)) {
            return null;
        }

        [$handle, $promptName] = explode(StarToolset::SEPARATOR, $name, 2);

        $connections = $star->connections()->get();
        $connection = $connections->first(fn (Connection $connection): bool => $connection->handle === $handle);
        $prompt = $connection?->prompts()->where('name', $promptName)->first();

        if ($connection === null || $prompt === null) {
            return null;
        }

        $switch = $star->promptSwitches()->where('connection_id', $connection->id)->where('prompt_name', $promptName)->first();

        return $this->expose($connection, $prompt, $switch?->enabled, Connection::idsWithSiblings($connections));
    }

    /**
     * @param  list<int>  $siblingIds  The Star's Connections that share their service with another of them.
     */
    private function expose(Connection $connection, ConnectionPrompt $prompt, ?bool $switch, array $siblingIds): StarPrompt
    {
        return new StarPrompt(
            connection: $connection,
            prompt: $prompt,
            name: $connection->handle.StarToolset::SEPARATOR.$prompt->name,
            enabled: $switch ?? true,
            switch: $switch,
            hasSiblings: in_array($connection->id, $siblingIds, true),
        );
    }
}
