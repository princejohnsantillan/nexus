<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\BillingCalendar;
use App\Exceptions\WeeklyToolCallLimitReached;
use App\Models\ToolCallCount;
use App\Models\User;

class CountToolCall
{
    /**
     * Refuse a call before anything is done for it when the user's week's
     * calls are already used up, so a call past the limit costs the server
     * nothing. It only reads the count: calls racing for the last one can
     * all pass it, and handle(), run as the call leaves, decides which one
     * goes. Pro has no limit, so nothing is read.
     *
     * @throws WeeklyToolCallLimitReached when the week's calls are used up
     */
    public function ensureCallsLeft(User $user): void
    {
        $limit = $user->plan()->toolCallsPerWeek();

        if ($limit !== null && $user->toolCallsThisWeek() >= $limit) {
            throw new WeeklyToolCallLimitReached($limit, BillingCalendar::nextWeekStart(now()));
        }
    }

    /**
     * Count one tool call that a Star of the user's is about to send, in
     * this billing week, unless the user's plan allows fewer a week than they
     * have already made. Pro has no weekly limit, but its calls are counted
     * too, for the Billing page's meter and for the rest of the week should
     * Pro end in it.
     *
     * The count and the check are one statement: insert the week's row with
     * one call, or add one to the row there is only while it is under the
     * limit. It holds the row while it writes, so calls that arrive together
     * are counted one after another, and two calls racing for the last one
     * can't both have it. `insert … on conflict … do update … where` is the
     * same on SQLite and Postgres, and the statement says whether it changed
     * a row: none when the week's calls are used up.
     *
     * @throws WeeklyToolCallLimitReached when the week's calls are used up; the call is not counted
     */
    public function handle(User $user): void
    {
        $limit = $user->plan()->toolCallsPerWeek();
        $now = now();

        if ($limit !== null && $limit < 1) {
            throw new WeeklyToolCallLimitReached($limit, BillingCalendar::nextWeekStart($now));
        }

        $query = ToolCallCount::query();
        $grammar = $query->getQuery()->getGrammar();
        $table = $grammar->wrapTable($query->getModel()->getTable());
        $calls = $table.'.'.$grammar->wrap('calls');

        $sql = "insert into {$table} (user_id, week_starts_on, calls) values (?, ?, 1) on conflict (user_id, week_starts_on) do update set calls = {$calls} + 1";
        $bindings = [$user->id, ToolCallCount::weekOf($now)];

        if ($limit !== null) {
            $sql .= " where {$calls} < ?";
            $bindings[] = $limit;
        }

        $counted = $query->getConnection()->affectingStatement($sql, $bindings) === 1;

        if (! $counted && $limit !== null) {
            throw new WeeklyToolCallLimitReached($limit, BillingCalendar::nextWeekStart($now));
        }
    }
}
