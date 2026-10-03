<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enums\ProReminder;
use App\Models\User;
use App\Notifications\ProReminderNotification;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * The emails about Pro ending (ProReminder), sent once per `pro_until`.
 * Each email's column on `users` keeps the `pro_until` it went for, so
 * extending Pro, which moves `pro_until`, starts over for the new date, and
 * an email that hasn't gone yet waits for it. Users without an email
 * address are skipped, and get theirs if they add one while it is due.
 */
final class ProReminders
{
    /**
     * The users due the email now: `pro_until` is in its window, they have
     * an email address, and it hasn't gone for this `pro_until`.
     *
     * @return LazyCollection<int, User>
     */
    public function due(ProReminder $reminder): LazyCollection
    {
        [$after, $until] = $reminder->window(CarbonImmutable::now());

        return User::query()
            ->whereNotNull('email')
            ->where('pro_until', '>', $after)
            ->where('pro_until', '<=', $until)
            ->where($this->notSentFor($reminder))
            ->lazyById();
    }

    /**
     * Queue the email to everyone due it, and return how many that was.
     */
    public function send(ProReminder $reminder): int
    {
        $sent = 0;

        foreach ($this->due($reminder) as $user) {
            if ($this->sendTo($user, $reminder)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Claim the user for the email, then queue it. The claim notes the
     * `pro_until` in the email's column only while `pro_until` is unchanged
     * and the email hasn't gone for it, so two runs at once, or a payment
     * moving `pro_until` meanwhile, never send it twice or for the wrong
     * date. If queueing fails the claim is undone, and the next run tries again.
     */
    private function sendTo(User $user, ProReminder $reminder): bool
    {
        $proUntil = $user->pro_until;

        if ($proUntil === null) {
            return false;
        }

        return DB::transaction(function () use ($user, $reminder, $proUntil): bool {
            $claimed = User::query()
                ->whereKey($user->id)
                ->where('pro_until', $proUntil)
                ->where($this->notSentFor($reminder))
                ->update([$reminder->sentForColumn() => $proUntil]);

            if ($claimed === 0) {
                return false;
            }

            $user->notify(new ProReminderNotification($reminder, $proUntil));

            return true;
        });
    }

    /**
     * Matches users the email hasn't gone to for their current `pro_until`.
     *
     * @return Closure(Builder<User>): Builder<User>
     */
    private function notSentFor(ProReminder $reminder): Closure
    {
        $column = $reminder->sentForColumn();

        return fn (Builder $query): Builder => $query
            ->whereNull($column)
            ->orWhereColumn($column, '!=', 'pro_until');
    }
}
