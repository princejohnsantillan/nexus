<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Billing\BillingCalendar;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A Free account has used its tool calls for the week, so a call isn't
 * forwarded until the next week starts.
 *
 * The message is Nexus's own and safe to show an agent: how many calls the
 * plan includes and when they reset, on the billing calendar.
 */
final class WeeklyToolCallLimitReached extends RuntimeException
{
    /**
     * @param  int  $limit  How many tool calls a week the account's plan includes.
     * @param  CarbonImmutable  $resetsAt  When the next week starts.
     */
    public function __construct(public readonly int $limit, public readonly CarbonImmutable $resetsAt)
    {
        parent::__construct(__('This Nexus account has used its :limit free tool calls this week. They reset on :reset Philippine time.', [
            'limit' => number_format($limit),
            'reset' => BillingCalendar::dayAndTime($resetsAt),
        ]));
    }
}
