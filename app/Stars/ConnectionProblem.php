<?php

declare(strict_types=1);

namespace App\Stars;

use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\Star;
use Illuminate\Support\Arr;

/**
 * One of a user's Connections that needs attention: what is wrong, how to
 * fix it, and which of the user's Stars it takes tools from.
 * ConnectionProblems finds them.
 */
final readonly class ConnectionProblem
{
    /**
     * @param  Connection  $connection  The Connection, without its credentials.
     * @param  list<array{id: int, name: string, toolsOn: int}>  $stars  The Stars that include it, by name, each with how many of the Connection's tools are on in it.
     */
    public function __construct(
        public Connection $connection,
        public array $stars = [],
    ) {}

    /**
     * Whether the server refused Nexus's sign-in, rather than failing another way.
     */
    public function needsSignIn(): bool
    {
        return $this->connection->status === ConnectionStatus::NeedsAuth;
    }

    /**
     * Whether signing in again on the server's own page fixes it: an OAuth
     * Connection that needs sign-in. Anything else is fixed on the
     * Connection's page.
     */
    public function canReconnect(): bool
    {
        return $this->needsSignIn() && $this->connection->usesOAuth();
    }

    /**
     * Where the fix is: the Reconnect route, or the Connection's page.
     */
    public function fixUrl(): string
    {
        return $this->canReconnect()
            ? route('connections.connect', $this->connection)
            : route('connections.show', $this->connection);
    }

    /**
     * What the fix's button says, naming the Connection when $withName is
     * true ("Reconnect Notion").
     */
    public function fixLabel(bool $withName = false): string
    {
        if ($withName) {
            return $this->canReconnect()
                ? __('Reconnect :name', ['name' => $this->connection->name])
                : __('Open :name', ['name' => $this->connection->name]);
        }

        return $this->canReconnect() ? __('Reconnect') : __('Open');
    }

    /**
     * The problem in a few words, naming the Connection: "Notion needs sign-in".
     */
    public function headline(): string
    {
        return $this->needsSignIn()
            ? __(':name needs sign-in', ['name' => $this->connection->name])
            : __(':name has an error', ['name' => $this->connection->name]);
    }

    /**
     * The problem as a sentence, naming the Connection: "Notion needs you to sign in again."
     */
    public function summary(): string
    {
        return $this->needsSignIn()
            ? __(':name needs you to sign in again.', ['name' => $this->connection->name])
            : __('Nexus couldn\'t load the tools of :name.', ['name' => $this->connection->name]);
    }

    /**
     * Why, in Nexus's own words: the error the last refresh or sign-in
     * recorded, or what the status means when none was.
     */
    public function reason(): string
    {
        return $this->connection->last_error ?? ($this->needsSignIn()
            ? __('Sign-in isn\'t finished. Reconnect to sign in and load its tools.')
            : __('Nexus couldn\'t load its tools.'));
    }

    /**
     * The status colour it is shown in.
     *
     * @return 'warning'|'danger'
     */
    public function tone(): string
    {
        return $this->needsSignIn() ? 'warning' : 'danger';
    }

    /**
     * Whether the Star includes the Connection.
     */
    public function affects(Star $star): bool
    {
        return array_any($this->stars, fn (array $included): bool => $included['id'] === $star->id);
    }

    /**
     * How many of the Star's tools are unavailable: the Connection's tools
     * that are on in it.
     */
    public function unavailableToolsIn(Star $star): int
    {
        return array_find($this->stars, fn (array $included): bool => $included['id'] === $star->id)['toolsOn'] ?? 0;
    }

    /**
     * How many tools in which Stars are unavailable, as a sentence ("9 tools
     * in Research are unavailable."), or null when none of the Connection's
     * tools is on in any Star.
     */
    public function impact(): ?string
    {
        $stars = array_values(array_filter($this->stars, fn (array $included): bool => $included['toolsOn'] > 0));

        if ($stars === []) {
            return null;
        }

        return trans_choice(':count tool in :stars is unavailable.|:count tools in :stars are unavailable.', array_sum(array_column($stars, 'toolsOn')), [
            'stars' => Arr::join(array_column($stars, 'name'), ', ', __(' and ')),
        ]);
    }
}
