<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * How far back the Activity page looks.
 */
enum ActivityRange: string
{
    case LastHour = '1h';

    case LastDay = '24h';

    case LastWeek = '7d';

    case LastMonth = '30d';

    /**
     * The short name on the range picker, which is also its value in the
     * address bar.
     */
    public function label(): string
    {
        return $this->value;
    }

    /**
     * The range in words, to finish "in the last …".
     */
    public function description(): string
    {
        return match ($this) {
            self::LastHour => __('hour'),
            self::LastDay => __('24 hours'),
            self::LastWeek => __('7 days'),
            self::LastMonth => __('30 days'),
        };
    }

    /**
     * The earliest moment the range covers, counting back from now.
     */
    public function start(CarbonImmutable $now): CarbonImmutable
    {
        return match ($this) {
            self::LastHour => $now->subHour(),
            self::LastDay => $now->subDay(),
            self::LastWeek => $now->subDays(7),
            self::LastMonth => $now->subDays(30),
        };
    }
}
