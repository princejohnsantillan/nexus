<?php

declare(strict_types=1);

namespace App\Stars;

use App\Enums\ConnectionStatus;
use App\Enums\NewToolPolicy;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Which of a user's Connections need attention, and what that costs their Stars.
 *
 * A Connection needs attention when its status is needs sign-in or error:
 * its server refused Nexus's sign-in, or its last refresh failed another
 * way, so agents can't use its tools until the user fixes it. The sidebar,
 * the Stars' cards, the banner on a Star's pages and the Connections page
 * all ask here, so they agree.
 */
class ConnectionProblems
{
    /**
     * The statuses that need the user's attention.
     *
     * @var list<ConnectionStatus>
     */
    public const array STATUSES = [ConnectionStatus::NeedsAuth, ConnectionStatus::Error];

    /**
     * The columns a problem's Connection is read with: what its problem is
     * shown with, and never its credentials.
     *
     * @var list<string>
     */
    private const array CONNECTION_COLUMNS = ['id', 'user_id', 'connector_key', 'name', 'handle', 'url', 'auth_type', 'status', 'last_error'];

    /**
     * The problem with the Connection, or null when it needs no attention.
     * Its Stars aren't looked up, so the problem names none.
     */
    public static function of(Connection $connection): ?ConnectionProblem
    {
        return in_array($connection->status, self::STATUSES, true) ? new ConnectionProblem($connection) : null;
    }

    /**
     * The user's Connections that need attention, by name, each with the
     * user's Stars that include it and how many of its tools are on in
     * each. One query, however many there are.
     *
     * @return list<ConnectionProblem>
     */
    public function forUser(User $user): array
    {
        return $this->find($user->id);
    }

    /**
     * The problems of the Star's user that the Star includes, by Connection name.
     *
     * @return list<ConnectionProblem>
     */
    public function forStar(Star $star): array
    {
        return array_values(array_filter($this->find($star->user_id), fn (ConnectionProblem $problem): bool => $problem->affects($star)));
    }

    /**
     * The user's problems by the id of each Star that includes them, each
     * Star's by Connection name. Stars without any are left out.
     *
     * @return array<int, list<ConnectionProblem>>
     */
    public function byStar(User $user): array
    {
        $byStar = [];

        foreach ($this->find($user->id) as $problem) {
            foreach ($problem->stars as $star) {
                $byStar[$star['id']][] = $problem;
            }
        }

        return $byStar;
    }

    /**
     * @return list<ConnectionProblem>
     */
    private function find(int $userId): array
    {
        // One row for each Star that includes a Connection, or one with no
        // Star for a Connection in none. Only the user's own Stars are
        // joined, though nothing puts a Connection in anyone else's.
        $rows = DB::table('connections')
            ->select(array_map(fn (string $column): string => 'connections.'.$column, self::CONNECTION_COLUMNS))
            ->addSelect(['stars.id as star_id', 'stars.name as star_name'])
            ->selectSub($this->toolsOn(...), 'tools_on')
            ->leftJoin('connection_star', 'connection_star.connection_id', '=', 'connections.id')
            ->leftJoin('stars', fn (JoinClause $join): JoinClause => $join->on('stars.id', '=', 'connection_star.star_id')->whereColumn('stars.user_id', 'connections.user_id'))
            ->where('connections.user_id', $userId)
            ->whereIn('connections.status', array_column(self::STATUSES, 'value'))
            ->orderBy('connections.name')
            ->orderBy('connections.id')
            ->orderBy('stars.name')
            ->orderBy('stars.id')
            ->get();

        $connections = [];
        $stars = [];

        foreach ($rows as $row) {
            $id = $this->number($row->id);
            $stars[$id] ??= [];

            if (! isset($connections[$id])) {
                $attributes = [];

                foreach (self::CONNECTION_COLUMNS as $column) {
                    $attributes[$column] = $row->{$column};
                }

                $connections[$id] = (new Connection)->newFromBuilder($attributes);
            }

            if ($row->star_id !== null) {
                $stars[$id][] = [
                    'id' => $this->number($row->star_id),
                    'name' => is_string($row->star_name) ? $row->star_name : '',
                    'toolsOn' => $this->number($row->tools_on),
                ];
            }
        }

        $problems = [];

        foreach ($connections as $id => $connection) {
            $problems[] = new ConnectionProblem($connection, $stars[$id]);
        }

        return $problems;
    }

    /**
     * Counts the Connection's tools that are on in the Star, by the rule
     * StarToolset follows: the user's own switch in that Star, or else the
     * Star's new-tool policy (NewToolPolicy::enables()) for the tool's
     * annotations.
     */
    private function toolsOn(Builder $query): void
    {
        $query->from('connection_tools')
            ->selectRaw('count(*)')
            ->leftJoin('star_tool_switches', fn (JoinClause $join): JoinClause => $join
                ->on('star_tool_switches.star_id', '=', 'stars.id')
                ->on('star_tool_switches.connection_id', '=', 'connection_tools.connection_id')
                ->on('star_tool_switches.tool_name', '=', 'connection_tools.name'))
            ->whereColumn('connection_tools.connection_id', 'connections.id')
            ->where(fn (Builder $on): Builder => $on
                ->where('star_tool_switches.enabled', true)
                ->orWhere(fn (Builder $byPolicy): Builder => $byPolicy
                    ->whereNull('star_tool_switches.id')
                    ->where(fn (Builder $policy): Builder => $policy
                        ->where('stars.new_tool_policy', NewToolPolicy::All->value)
                        ->orWhere(fn (Builder $readOnly): Builder => $readOnly
                            ->where('stars.new_tool_policy', NewToolPolicy::ReadOnly->value)
                            ->where('connection_tools.read_only', true)))));
    }

    /**
     * A whole number as the database returned it: an integer, or a string
     * of digits from drivers that return numbers as text.
     */
    private function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
