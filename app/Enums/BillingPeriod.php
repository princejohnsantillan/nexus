<?php

declare(strict_types=1);

namespace App\Enums;

use App\Billing\BillingCalendar;
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
     * The moment one period after the given one, counted on the billing
     * calendar (Philippine time), so a month after a day Pro shows as May 1
     * is June 1 there, whatever day it is in UTC. A month after January 31
     * is the end of February, not March 3. The result keeps the given
     * moment's timezone, so it is stored as the right instant.
     */
    public function after(CarbonImmutable $moment): CarbonImmutable
    {
        $local = BillingCalendar::local($moment);

        $later = match ($this) {
            self::Month => $local->addMonthNoOverflow(),
            self::Year => $local->addYearNoOverflow(),
        };

        return $later->setTimezone($moment->getTimezone());
    }
}
