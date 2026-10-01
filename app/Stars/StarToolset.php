<?php

declare(strict_types=1);

namespace App\Stars;

use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\StarToolSwitch;
use Illuminate\Database\Eloquent\Collection;

/**
 * A Star's tools as clients see them.
 *
 * Every tool in the catalog of each Connection the Star includes is exposed
 * as `{handle}__{tool}`. It is on when the user switched it on in this Star,
 * off when they switched it off, and otherwise as the Star's new-tool policy
 * says for the tool's current annotations, so a tool that stops being
 * read-only on its server stops being on under the read-only policy.
 * Switches are kept by tool name, so they survive catalog refreshes.
 *
 * Connections of the same service in the Star (siblings, such as two GitHub
 * accounts) are told apart by their account labels, which lead their tools'
 * descriptions.
 *
 * What the tools are made from is read from the database once per change
 * and kept in StarListCache, so listing and looking them up doesn't read
 * it again.
 */
class StarToolset
{
    /**
     * What joins a Connection's handle to its tool's name. Handles never
     * contain an underscore, so the first separator ends the handle.
     */
    public const string SEPARATOR = '__';

    public function __construct(private readonly StarListCache $starLists) {}

    /**
     * Every tool of the Star's Connections, on or off: Connections by name,
     * then each one's tools by name.
     *
     * Each tool's Connection is as cached for the Star's lists: current in
     * everything but its credentials, which it leaves out, so it can't be
     * used to call its server. tool() gives a Connection to call.
     *
     * @return list<StarTool>
     */
    public function tools(Star $star): array
    {
        [$connections, $toolsByConnection, $switches] = $this->catalog($star);
        $siblingIds = Connection::idsWithSiblings($connections);
        $tools = [];

        foreach ($connections as $connection) {
            foreach ($toolsByConnection[$connection->id] ?? [] as $tool) {
                $tools[] = $this->expose($star, $connection, $tool, $switches[$connection->id][$tool->name] ?? null, $siblingIds);
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
     *
     * The tool is found in the cache, and its Connection then read as
     * stored, credentials and all, to call its server with: the one query
     * a call makes, which also finds nothing once the Star no longer
     * includes the Connection.
     */
    public function tool(Star $star, string $name): ?StarTool
    {
        if (! str_contains($name, self::SEPARATOR)) {
            return null;
        }

        [$handle, $toolName] = explode(self::SEPARATOR, $name, 2);
        [$connections, $toolsByConnection, $switches] = $this->catalog($star);

        $listed = $connections->firstWhere('handle', $handle);
        $tool = $listed === null ? null : array_find($toolsByConnection[$listed->id] ?? [], fn (ConnectionTool $tool): bool => $tool->name === $toolName);

        if ($tool === null) {
            return null;
        }

        $connection = $star->connections()->whereKey($tool->connection_id)->first();

        if ($connection === null) {
            return null;
        }

        return $this->expose($star, $connection, $tool, $switches[$connection->id][$toolName] ?? null, Connection::idsWithSiblings($connections));
    }

    /**
     * What the Star's tools are made from, as cached for it: its
     * Connections by name, their tools by Connection id (each by name), and
     * the user's switches by Connection id and tool name.
     *
     * @return array{Collection<int, Connection>, array<int, list<ConnectionTool>>, array<int, array<string, bool>>}
     */
    private function catalog(Star $star): array
    {
        $rows = $this->starLists->remember($star, 'tools', fn (): array => $this->rows($star), $this->restore(...));

        $tools = [];

        foreach (ConnectionTool::hydrate($rows['tools']) as $tool) {
            $tools[$tool->connection_id][] = $tool;
        }

        $switches = [];

        foreach (StarToolSwitch::hydrate($rows['switches']) as $switch) {
            $switches[$switch->connection_id][$switch->tool_name] = $switch->enabled;
        }

        return [Connection::hydrate($rows['connections']), $tools, $switches];
    }

    /**
     * The rows the Star's tools are made from, as the database returns
     * them: its Connections by name, without their encrypted credentials,
     * which the cache never holds; their tools by name; and the switches.
     *
     * @return array{connections: array<mixed>, tools: array<mixed>, switches: array<mixed>}
     */
    private function rows(Star $star): array
    {
        $connections = $star->connections()->orderBy('connections.name')->orderBy('connections.id')->toBase()->get(['connections.*']);

        foreach ($connections as $connection) {
            unset($connection->secrets);
        }

        return [
            'connections' => $connections->all(),
            'tools' => ConnectionTool::query()->whereIn('connection_id', $connections->pluck('id'))->orderBy('name')->toBase()->get()->all(),
            'switches' => $star->toolSwitches()->toBase()->get(['connection_id', 'tool_name', 'enabled'])->all(),
        ];
    }

    /**
     * The rows as cached, or null when the cache holds something else.
     *
     * @return array{connections: array<mixed>, tools: array<mixed>, switches: array<mixed>}|null
     */
    private function restore(mixed $cached): ?array
    {
        if (! is_array($cached) || ! is_array($cached['connections'] ?? null) || ! is_array($cached['tools'] ?? null) || ! is_array($cached['switches'] ?? null)) {
            return null;
        }

        return ['connections' => $cached['connections'], 'tools' => $cached['tools'], 'switches' => $cached['switches']];
    }

    /**
     * @param  list<int>  $siblingIds  The Star's Connections that share their service with another of them.
     */
    private function expose(Star $star, Connection $connection, ConnectionTool $tool, ?bool $switch, array $siblingIds): StarTool
    {
        return new StarTool(
            connection: $connection,
            tool: $tool,
            name: $connection->handle.self::SEPARATOR.$tool->name,
            enabled: $switch ?? $star->new_tool_policy->enables($tool),
            switch: $switch,
            hasSiblings: in_array($connection->id, $siblingIds, true),
        );
    }
}
