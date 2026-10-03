<?php

declare(strict_types=1);

namespace App\Stars;

use App\Enums\NewToolPolicy;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use Illuminate\Database\Eloquent\Collection;

/**
 * What saving a Star's overview would change, compared with the Star as
 * stored: the Connections it would add and take out, and its details, with
 * what each change does, in words, for the unsaved-changes bar.
 *
 * Saving goes through UpdateStarConnections, so a Connection added starts
 * from the Star's new-tool policy, and one taken out loses the switches the
 * user set for its tools and prompts in this Star. Adding one says how many
 * of its tools the policy turns on ("Adding DeepWiki turns on its 3
 * read-only tools"); taking one out says how many switches it forgets
 * ("Removing Linear forgets 4 switches") or, when it has none, how many of
 * its tools it turns off, which the policy decides too.
 */
final readonly class StarChanges
{
    /**
     * @param  list<int>  $connectionIds  The Connections saving would add or take out.
     * @param  bool  $changesDetails  Whether saving would change the Star's name or description.
     * @param  list<string>  $consequences  What each change does: the Connections' in the order they are listed, by name, then the details'.
     */
    private function __construct(
        public array $connectionIds,
        public bool $changesDetails,
        public array $consequences,
    ) {}

    /**
     * The changes from the Star as stored to the overview's fields: the ids
     * of the Connections ticked, and the name and description as typed,
     * which are saved trimmed. A Connection that isn't the Star's user's is
     * no change, as saving refuses it.
     *
     * @param  list<string>  $connectionIds
     */
    public static function of(Star $star, array $connectionIds, string $name, string $description): self
    {
        $stored = $star->connections()->get(['connections.id'])->map(fn (Connection $connection): int => $connection->id)->all();
        $ticked = array_map(intval(...), $connectionIds);

        $removed = array_values(array_diff($stored, $ticked));
        $changed = [...array_diff($ticked, $stored), ...$removed];

        $connections = $changed === [] ? new Collection : Connection::query()
            ->where('user_id', $star->user_id)
            ->whereKey($changed)
            ->with('tools:id,connection_id,read_only')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $switches = self::switchCounts($star, $removed);

        $consequences = array_values($connections->map(fn (Connection $connection): string => in_array($connection->id, $removed, true)
            ? self::removing($star->new_tool_policy, $connection, $switches[$connection->id] ?? 0)
            : self::adding($star->new_tool_policy, $connection))->all());

        $renamed = trim($name) !== $star->name;
        $redescribed = trim($description) !== ($star->description ?? '');

        if ($renamed && trim($name) !== '') {
            $consequences[] = __('Renaming the Star keeps its URL');
        }

        if ($redescribed) {
            $consequences[] = trim($description) === '' ? __('Agents no longer see a description') : __('Agents see the new description');
        }

        return new self(
            array_values($connections->map(fn (Connection $connection): int => $connection->id)->all()),
            $renamed || $redescribed,
            $consequences,
        );
    }

    /**
     * Whether saving would change nothing.
     */
    public function isEmpty(): bool
    {
        return $this->connectionIds === [] && ! $this->changesDetails;
    }

    private static function adding(NewToolPolicy $policy, Connection $connection): string
    {
        $on = self::toolsOn($policy, $connection);

        return match (true) {
            $on === 0 => __('Adding :name turns on none of its tools', ['name' => $connection->name]),
            $policy === NewToolPolicy::ReadOnly => trans_choice('Adding :name turns on its :count read-only tool|Adding :name turns on its :count read-only tools', $on, ['name' => $connection->name]),
            default => trans_choice('Adding :name turns on its :count tool|Adding :name turns on its :count tools', $on, ['name' => $connection->name]),
        };
    }

    /**
     * How many switches the user set in the Star for the tools and prompts
     * of each of these Connections, by Connection id: one query for each
     * kind of switch, however many Connections.
     *
     * @param  list<int>  $connectionIds
     * @return array<int, int>
     */
    private static function switchCounts(Star $star, array $connectionIds): array
    {
        if ($connectionIds === []) {
            return [];
        }

        $counts = [];

        foreach ($star->toolSwitches()->whereIn('connection_id', $connectionIds)->get(['connection_id']) as $switch) {
            $counts[$switch->connection_id] = ($counts[$switch->connection_id] ?? 0) + 1;
        }

        foreach ($star->promptSwitches()->whereIn('connection_id', $connectionIds)->get(['connection_id']) as $switch) {
            $counts[$switch->connection_id] = ($counts[$switch->connection_id] ?? 0) + 1;
        }

        return $counts;
    }

    private static function removing(NewToolPolicy $policy, Connection $connection, int $switches): string
    {
        if ($switches > 0) {
            return trans_choice('Removing :name forgets :count switch|Removing :name forgets :count switches', $switches, ['name' => $connection->name]);
        }

        $on = self::toolsOn($policy, $connection);

        return match (true) {
            $on === 0 => __('Removing :name turns off none of its tools', ['name' => $connection->name]),
            $policy === NewToolPolicy::ReadOnly => trans_choice('Removing :name turns off its :count read-only tool|Removing :name turns off its :count read-only tools', $on, ['name' => $connection->name]),
            default => trans_choice('Removing :name turns off its :count tool|Removing :name turns off its :count tools', $on, ['name' => $connection->name]),
        };
    }

    /**
     * How many of the Connection's tools the policy turns on: all of them
     * that are on in the Star while the user has set no switch for them.
     */
    private static function toolsOn(NewToolPolicy $policy, Connection $connection): int
    {
        return $connection->tools->filter(fn (ConnectionTool $tool): bool => $policy->enables($tool))->count();
    }
}
