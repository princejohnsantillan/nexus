<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The emails Nexus sends because Pro is prepaid and never renews on its
 * own: one in Pro's last User::PRO_ENDING_SOON_DAYS days, and one once it
 * has ended. Each goes once per `pro_until`: its column on `users` keeps
 * the `pro_until` it was last sent for (App\Billing\ProReminders).
 */
enum ProReminder: string
{
    case EndsSoon = 'ends_soon';

    case Ended = 'ended';

    /**
     * How long after Pro ends its "has ended" email may still go out. A late
     * run still sends it, but the first run after a deploy doesn't email
     * everyone whose Pro ended long ago.
     */
    public const int ENDED_WITHIN_HOURS = 48;

    /**
     * "Pro ends soon" or "Pro has ended", as the command lists them.
     */
    public function label(): string
    {
        return match ($this) {
            self::EndsSoon => __('Pro ends soon'),
            self::Ended => __('Pro has ended'),
        };
    }

    /**
     * The `users` column that keeps the `pro_until` the email was last sent for.
     */
    public function sentForColumn(): string
    {
        return match ($this) {
            self::EndsSoon => 'pro_ends_soon_emailed_for',
            self::Ended => 'pro_ended_emailed_for',
        };
    }

    /**
     * When `pro_until` falls for the email to be due at the moment: after
     * the first of the two, and no later than the second.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function window(CarbonImmutable $now): array
    {
        return match ($this) {
            self::EndsSoon => [$now, $now->addDays(User::PRO_ENDING_SOON_DAYS)],
            self::Ended => [$now->subHours(self::ENDED_WITHIN_HOURS), $now],
        };
    }

    /**
     * Whether the email is due at the moment for Pro ending at `$proUntil`:
     * whether `$proUntil` falls in its window().
     */
    public function isDue(CarbonImmutable $proUntil, CarbonImmutable $now): bool
    {
        [$after, $until] = $this->window($now);

        return $proUntil->isAfter($after) && $proUntil->lessThanOrEqualTo($until);
    }
}
