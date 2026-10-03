<?php

declare(strict_types=1);

namespace App\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Billing's own clock: Nexus bills in pesos, so billing dates (when Pro
 * ends, when a payment was made) show in Philippine time, from
 * `nexus.billing.timezone`, whatever the app's timezone is. The weekly
 * tool-call limit counts weeks on the same clock, from Monday 00:00.
 */
final class BillingCalendar
{
    public static function timezone(): string
    {
        return config()->string('nexus.billing.timezone');
    }

    /**
     * The moment, on the billing timezone's clock.
     */
    public static function local(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::timezone());
    }

    /**
     * The moment's date in the billing timezone, e.g. "Oct 3, 2026".
     */
    public static function date(CarbonInterface $moment): string
    {
        return self::local($moment)->format('M j, Y');
    }

    /**
     * The moment's date in the billing timezone without the year, e.g. "Oct 3".
     */
    public static function shortDate(CarbonInterface $moment): string
    {
        return self::local($moment)->format('M j');
    }

    /**
     * The moment's day and time in the billing timezone, e.g. "Monday, Oct 5 at 12:00 AM".
     */
    public static function dayAndTime(CarbonInterface $moment): string
    {
        return self::local($moment)->format('l, M j \a\t g:i A');
    }

    /**
     * When the billing week the moment falls in started: Monday 00:00 in the
     * billing timezone.
     */
    public static function weekStart(CarbonInterface $moment): CarbonImmutable
    {
        return self::local($moment)->startOfWeek(CarbonInterface::MONDAY);
    }

    /**
     * When the billing week after the moment's starts, which is when the
     * week's tool calls reset.
     */
    public static function nextWeekStart(CarbonInterface $moment): CarbonImmutable
    {
        return self::weekStart($moment)->addWeek();
    }
}
