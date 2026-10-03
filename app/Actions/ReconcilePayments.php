<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Exceptions\PayMongoRequestFailed;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Settle the pending payments nothing else settled: a checkout paid by a
 * user who never came back to the return page, when the webhook didn't
 * reach Nexus either, and checkouts left unpaid. The scheduler runs it
 * every 10 minutes (`nexus:billing:reconcile`).
 *
 * A pending payment older than CONFIRM_AFTER_MINUTES (the return page has
 * stopped checking by then) is confirmed with ConfirmPayment. One older
 * than EXPIRE_AFTER_HOURS is closed with ExpireCheckout instead, as
 * ExpireStaleCheckout closes a user's older checkouts: it asks PayMongo
 * first and confirms the payment if it was paid after all, and otherwise
 * expires it on PayMongo and marks it expired. One that never got a
 * checkout session charged nothing and is simply marked expired. It runs
 * the actions itself rather than queueing them, so it can say how each
 * payment ended up.
 *
 * One run closes at most EXPIRES_PER_RUN stale checkouts, and starts on
 * no payment once it has run for RUNS_FOR_AT_MOST seconds, so a slow
 * PayMongo can't stall it: what it leaves waits for the next run. Each
 * payment it starts on gets `reconciled_at`, and each run takes first the
 * payments it asked PayMongo about longest ago, counting one never asked
 * about from when it was created. So payments PayMongo keeps failing on
 * (such as a checkout it no longer knows) take their turn behind the
 * others instead of filling every run.
 * When PayMongo can't be asked, the payment stays pending for the next run.
 * Every step goes through ConfirmPayment's locks, so running it again, or
 * while the return page or the webhook confirms the same payment, applies a
 * payment once.
 */
final readonly class ReconcilePayments
{
    /**
     * How old a pending payment is before it is confirmed here, in minutes.
     */
    public const int CONFIRM_AFTER_MINUTES = 2;

    /**
     * How old a pending payment is before its checkout is expired, in hours.
     */
    public const int EXPIRE_AFTER_HOURS = 24;

    /**
     * How many stale checkouts one run closes at most.
     */
    public const int EXPIRES_PER_RUN = 50;

    /**
     * How long one run keeps starting on payments, in seconds. A payment
     * started takes at most three PayMongo requests (read, expire, read
     * again) of 15 seconds each, so the run ends well before the next one,
     * 10 minutes later, and before its overlap lock lapses.
     */
    public const int RUNS_FOR_AT_MOST = 5 * 60;

    public function __construct(
        private ConfirmPayment $confirmPayment,
        private ExpireCheckout $expireCheckout,
    ) {}

    /**
     * @return array{paid: int, expired: int, pending: int, deferred: int} How many of the payments it looked at ended up paid, expired, and still pending, and how many it left for the next run.
     */
    public function handle(): array
    {
        $now = CarbonImmutable::now();
        $stopAt = $now->addSeconds(self::RUNS_FOR_AT_MOST);
        $expireBefore = $now->subHours(self::EXPIRE_AFTER_HOURS);
        $outcomes = ['paid' => 0, 'expired' => 0, 'pending' => 0, 'deferred' => 0];

        $recent = $this->longestUnaskedFirst(Payment::query()
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '>', $expireBefore)
            ->where('created_at', '<=', $now->subMinutes(self::CONFIRM_AFTER_MINUTES)))
            ->get()
            ->map(fn (Payment $payment): array => [$payment, false]);

        $stale = Payment::query()
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '<=', $expireBefore);

        $outcomes['deferred'] = max(0, $stale->count() - self::EXPIRES_PER_RUN);

        $staleBatch = $this->longestUnaskedFirst($stale)->limit(self::EXPIRES_PER_RUN)->get()
            ->map(fn (Payment $payment): array => [$payment, true]);

        foreach ($recent->concat($staleBatch) as [$payment, $isStale]) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($stopAt)) {
                $outcomes['deferred']++;

                continue;
            }

            Payment::query()->whereKey($payment->id)->update(['reconciled_at' => CarbonImmutable::now()]);

            $outcomes[$this->reconcile($payment, $isStale)->status->value]++;
        }

        return $outcomes;
    }

    /**
     * Order the payments by when PayMongo was last asked about them here,
     * or else when they were created, longest ago first.
     *
     * @param  Builder<Payment>  $payments
     * @return Builder<Payment>
     */
    private function longestUnaskedFirst(Builder $payments): Builder
    {
        return $payments->orderByRaw('coalesce(reconciled_at, created_at)')->orderBy('id');
    }

    private function reconcile(Payment $payment, bool $isStale): Payment
    {
        try {
            if (! $isStale) {
                return $this->confirmPayment->handle($payment);
            }

            if ($payment->checkout_session_id === null) {
                return $this->expireWithoutCheckout($payment);
            }

            return $this->expireCheckout->handle($payment);
        } catch (PayMongoRequestFailed) {
            return $payment;
        }
    }

    /**
     * Mark expired a payment whose checkout session was never created, so
     * nobody could have paid it.
     */
    private function expireWithoutCheckout(Payment $payment): Payment
    {
        Payment::query()
            ->whereKey($payment->id)
            ->where('status', PaymentStatus::Pending)
            ->whereNull('checkout_session_id')
            ->update(['status' => PaymentStatus::Expired]);

        return $payment->refresh();
    }
}
