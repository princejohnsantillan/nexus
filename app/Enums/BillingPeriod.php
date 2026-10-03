<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * How much Pro one payment buys: a month or a year. Pro is prepaid and
 * never renews on its own, so each payment is for one period.
 */
enum BillingPeriod: string
{
    case Month = 'month';

    case Year = 'year';

    /**
     * "Monthly" or "Yearly", as on the period picker and in payment descriptions.
     */
    public function label(): string
    {
        return match ($this) {
            self::Month => __('Monthly'),
            self::Year => __('Yearly'),
        };
    }

    /**
     * The moment one period after the given one. A month after January 31
     * is the end of February, not March 3.
     */
    public function after(CarbonImmutable $moment): CarbonImmutable
    {
        return match ($this) {
            self::Month => $moment->addMonthNoOverflow(),
            self::Year => $moment->addYearNoOverflow(),
        };
    }
}
