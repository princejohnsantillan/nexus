<?php

declare(strict_types=1);

namespace App\Stars;

use App\Models\ActivityEntry;
use App\Models\Star;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads from Activity how each of several Stars has been used lately, in
 * one query however many Stars there are: when each was last called, and
 * its calls in each of the last DAYS days.
 */
class StarCallHistory
{
    /**
     * How many days of calls a Star's history counts.
     */
    public const int DAYS = 14;

    /**
     * The history of each Star given, by Star id. A Star without calls in
     * Activity has no last call and a count of zero for every day.
     *
     * @param  Collection<int, Star>  $stars
     * @return array<int, StarCalls>
     */
    public function forStars(Collection $stars): array
    {
        if ($stars->isEmpty()) {
            return [];
        }

        $now = CarbonImmutable::now();

        // Entries are indexed by their user and time, so naming the Stars'
        // owners keeps the query to their own activity.
        $query = ActivityEntry::query()
            ->toBase()
            ->select('star_id')
            ->selectRaw('max(created_at) as last_called_at')
            ->whereIn('user_id', $stars->pluck('user_id')->unique()->values()->all())
            ->whereIn('star_id', $stars->modelKeys())
            ->groupBy('star_id');

        foreach (range(0, self::DAYS - 1) as $day) {
            $from = $now->subDays(self::DAYS - $day);

            $query->selectRaw("sum(case when created_at > ? and created_at <= ? then 1 else 0 end) as day_{$day}", [$from, $from->addDay()]);
        }

        $history = [];

        foreach ($query->get() as $row) {
            $history[$this->number($row->star_id)] = new StarCalls(
                is_string($row->last_called_at) ? CarbonImmutable::parse($row->last_called_at) : null,
                array_map(fn (int $day): int => $this->number($row->{"day_{$day}"}), range(0, self::DAYS - 1)),
            );
        }

        return $stars->mapWithKeys(fn (Star $star): array => [
            $star->id => $history[$star->id] ?? new StarCalls(null, array_fill(0, self::DAYS, 0)),
        ])->all();
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
