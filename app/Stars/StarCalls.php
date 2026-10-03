<?php

declare(strict_types=1);

namespace App\Stars;

use Carbon\CarbonImmutable;

/**
 * How a Star has been used lately, from its Activity: when it was last
 * called, and how many calls it had in each of the last days.
 */
final readonly class StarCalls
{
    /**
     * @param  CarbonImmutable|null  $lastCalledAt  When the newest call still in Activity was made, or null when there is none.
     * @param  list<int>  $daily  The calls in each of the last days, as 24-hour windows ending now, oldest first.
     */
    public function __construct(
        public ?CarbonImmutable $lastCalledAt,
        public array $daily,
    ) {}

    /**
     * How many calls the Star had in the days $daily covers.
     */
    public function recentTotal(): int
    {
        return array_sum($this->daily);
    }
}
