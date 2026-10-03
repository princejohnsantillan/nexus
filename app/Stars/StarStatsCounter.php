<?php

declare(strict_types=1);

namespace App\Stars;

use App\Enums\ActivityStatus;
use App\Models\ActivityEntry;
use App\Models\Star;
use Illuminate\Database\Eloquent\Builder;

/**
 * Works out a Star's stats for its overview from its tools and its own
 * activity entries.
 *
 * The calls are those of the last 24 hours, up to now: an entry exactly 24
 * hours old is out. They are counted in the database, in one query, so a
 * busy Star's entries are never loaded: each of the 24 hours, oldest first,
 * is a conditional count, which works the same on SQLite and Postgres.
 */
final readonly class StarStatsCounter
{
    /**
     * How many hours the call figures cover, one sparkline point each.
     */
    public const int HOURS = 24;

    public function __construct(private StarToolset $toolset) {}

    public function for(Star $star): StarStats
    {
        $tools = $this->toolset->tools($star);
        $now = now()->startOfSecond();
        $since = $now->subHours(self::HOURS);

        $columns = ['count(*) as calls', 'sum(case when status <> ? then 1 else 0 end) as errors'];
        $bindings = [ActivityStatus::Ok->value];

        for ($hour = 0; $hour < self::HOURS; $hour++) {
            $columns[] = "sum(case when created_at > ? and created_at <= ? then 1 else 0 end) as hour_{$hour}";
            $bindings[] = $since->addHours($hour);
            $bindings[] = $since->addHours($hour + 1);
        }

        $counts = $this->entries($star)
            ->where('created_at', '>', $since)
            ->where('created_at', '<=', $now)
            ->selectRaw(implode(', ', $columns), $bindings)
            ->toBase()
            ->first();

        return new StarStats(
            toolsOn: count(array_filter($tools, fn (StarTool $tool): bool => $tool->enabled)),
            tools: count($tools),
            calls: $this->count($counts?->calls),
            callsByHour: array_map(fn (int $hour): int => $this->count($counts?->{"hour_{$hour}"}), range(0, self::HOURS - 1)),
            errors: $this->count($counts?->errors),
            lastCall: $this->entries($star)->latest()->latest('id')->first(),
        );
    }

    /**
     * The Star's activity entries. They are looked up within its user's,
     * which the activity table indexes by time.
     *
     * @return Builder<ActivityEntry>
     */
    private function entries(Star $star): Builder
    {
        return ActivityEntry::query()->where('user_id', $star->user_id)->where('star_id', $star->id);
    }

    /**
     * A count as the database returns it: an integer or a numeric string,
     * or null for a sum over no entries.
     */
    private function count(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
