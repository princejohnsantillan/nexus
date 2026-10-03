<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a user's account may do. A user is on Pro while their `pro_until`
 * is in the future, and on Free otherwise (User::plan()). The limits and
 * prices come from `nexus.plans`; a null limit means unlimited.
 *
 * Limits stop additions only: an account over a limit (an older account,
 * or a Pro that ended) keeps everything it has.
 */
enum Plan: string
{
    case Free = 'free';

    case Pro = 'pro';

    public function label(): string
    {
        return match ($this) {
            self::Free => __('Free'),
            self::Pro => __('Pro'),
        };
    }

    /**
     * How many Stars an account on the plan may have, or null for no limit.
     */
    public function starLimit(): ?int
    {
        return $this->limit('stars');
    }

    /**
     * How many Connections an account on the plan may have, or null for no limit.
     */
    public function connectionLimit(): ?int
    {
        return $this->limit('connections');
    }

    /**
     * How many tool calls an account on the plan may make each week, or null for no limit.
     */
    public function toolCallsPerWeek(): ?int
    {
        return $this->limit('tool_calls_per_week');
    }

    /**
     * What a period of the plan costs, in centavos. Free costs nothing.
     */
    public function price(BillingPeriod $period): int
    {
        return match ($this) {
            self::Free => 0,
            self::Pro => config()->integer("nexus.plans.pro.prices.{$period->value}"),
        };
    }

    /**
     * How much paying for a year saves over twelve monthly payments, in centavos.
     */
    public function yearlySaving(): int
    {
        return $this->price(BillingPeriod::Month) * 12 - $this->price(BillingPeriod::Year);
    }

    private function limit(string $name): ?int
    {
        $limit = config("nexus.plans.{$this->value}.{$name}");

        return is_int($limit) ? $limit : null;
    }
}
