<?php

declare(strict_types=1);

namespace App\Stars;

use App\Models\ActivityEntry;

/**
 * Whether a Star is working, at a glance: how many of its tools are on, how
 * many calls it had in the last 24 hours and how many of them failed, and
 * its latest call. StarStatsCounter works them out.
 */
final readonly class StarStats
{
    /**
     * @param  int  $toolsOn  How many of the Star's tools are on.
     * @param  int  $tools  How many tools the Star has, on or off.
     * @param  int  $calls  The Star's calls in the last 24 hours.
     * @param  list<int>  $callsByHour  Those calls in each of the 24 hours, oldest first: the last is the hour up to now.
     * @param  int  $errors  How many of those calls didn't end OK.
     * @param  ActivityEntry|null  $lastCall  The Star's latest call, however long ago, or null when it has none.
     */
    public function __construct(
        public int $toolsOn,
        public int $tools,
        public int $calls,
        public array $callsByHour,
        public int $errors,
        public ?ActivityEntry $lastCall,
    ) {}

    /**
     * The share of the last 24 hours' calls that didn't end OK, as a
     * percentage to one decimal place ("0.2%"), or null when there were no
     * calls. A few errors among many calls read "<0.1%" rather than "0%",
     * and a few successes among many errors ">99.9%" rather than "100%".
     */
    public function errorRate(): ?string
    {
        if ($this->calls === 0) {
            return null;
        }

        $rate = $this->errors / $this->calls * 100;

        return match (true) {
            $this->errors > 0 && $rate < 0.1 => '<0.1%',
            $this->errors < $this->calls && $rate > 99.9 => '>99.9%',
            default => rtrim(rtrim(number_format($rate, 1), '0'), '.').'%',
        };
    }
}
