<?php

declare(strict_types=1);

namespace App\Stars;

use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\Star;
use App\Models\StarPromptSwitch;
use Illuminate\Database\Eloquent\Collection;

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
 *
 * What the prompts are made from is kept in StarListCache, as for tools.
 */
class StarPrompts
{
    public function __construct(private readonly StarListCache $starLists) {}

    /**
     * Every prompt of the Star's Connections, on or off: Connections by
     * name, then each one's prompts by name.
     *
     * Each prompt's Connection is as cached for the Star's lists, without
     * its credentials, as StarToolset::tools() gives them; prompt() gives a
     * Connection to call.
     *
     * @return list<StarPrompt>
     */
    public function prompts(Star $star): array
    {
        [$connections, $promptsByConnection, $switches] = $this->catalog($star);
        $siblingIds = Connection::idsWithSiblings($connections);
        $prompts = [];

        foreach ($connections as $connection) {
            foreach ($promptsByConnection[$connection->id] ?? [] as $prompt) {
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
     *
     * Like StarToolset::tool(), it is found in the cache, and its
     * Connection then read as stored, while the Star still includes it.
     */
    public function prompt(Star $star, string $name): ?StarPrompt
    {
        if (! str_contains($name, StarToolset::SEPARATOR)) {
            return null;
        }

        [$handle, $promptName] = explode(StarToolset::SEPARATOR, $name, 2);
        [$connections, $promptsByConnection, $switches] = $this->catalog($star);

        $listed = $connections->firstWhere('handle', $handle);
        $prompt = $listed === null ? null : array_find($promptsByConnection[$listed->id] ?? [], fn (ConnectionPrompt $prompt): bool => $prompt->name === $promptName);

        if ($prompt === null) {
            return null;
        }

        $connection = $star->connections()->whereKey($prompt->connection_id)->first();

        if ($connection === null) {
            return null;
        }

        return $this->expose($connection, $prompt, $switches[$connection->id][$promptName] ?? null, Connection::idsWithSiblings($connections));
    }

    /**
     * What the Star's prompts are made from, as cached for it: its
     * Connections by name, their prompts by Connection id (each by name),
     * and the user's switches by Connection id and prompt name.
     *
     * @return array{Collection<int, Connection>, array<int, list<ConnectionPrompt>>, array<int, array<string, bool>>}
     */
    private function catalog(Star $star): array
    {
        $rows = $this->starLists->remember($star, 'prompts', fn (): array => $this->rows($star), $this->restore(...));

        $prompts = [];

        foreach (ConnectionPrompt::hydrate($rows['prompts']) as $prompt) {
            $prompts[$prompt->connection_id][] = $prompt;
        }

        $switches = [];

        foreach (StarPromptSwitch::hydrate($rows['switches']) as $switch) {
            $switches[$switch->connection_id][$switch->prompt_name] = $switch->enabled;
        }

        return [Connection::hydrate($rows['connections']), $prompts, $switches];
    }

    /**
     * The rows the Star's prompts are made from, as the database returns
     * them: its Connections by name, without their encrypted credentials,
     * which the cache never holds; their prompts by name; and the switches.
     *
     * @return array{connections: array<mixed>, prompts: array<mixed>, switches: array<mixed>}
     */
    private function rows(Star $star): array
    {
        $connections = $star->connections()->orderBy('connections.name')->orderBy('connections.id')->toBase()->get(['connections.*']);

        foreach ($connections as $connection) {
            unset($connection->secrets);
        }

        return [
            'connections' => $connections->all(),
            'prompts' => ConnectionPrompt::query()->whereIn('connection_id', $connections->pluck('id'))->orderBy('name')->toBase()->get()->all(),
            'switches' => $star->promptSwitches()->toBase()->get(['connection_id', 'prompt_name', 'enabled'])->all(),
        ];
    }

    /**
     * The rows as cached, or null when the cache holds something else.
     *
     * @return array{connections: array<mixed>, prompts: array<mixed>, switches: array<mixed>}|null
     */
    private function restore(mixed $cached): ?array
    {
        if (! is_array($cached) || ! is_array($cached['connections'] ?? null) || ! is_array($cached['prompts'] ?? null) || ! is_array($cached['switches'] ?? null)) {
            return null;
        }

        return ['connections' => $cached['connections'], 'prompts' => $cached['prompts'], 'switches' => $cached['switches']];
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
