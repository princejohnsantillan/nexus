<?php

declare(strict_types=1);

namespace App\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Billing's own clock: Nexus bills in pesos, so billing dates (when Pro
 * ends, when a payment was made) show in Philippine time, from
 * `nexus.billing.timezone`, whatever the app's timezone is.
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
}
