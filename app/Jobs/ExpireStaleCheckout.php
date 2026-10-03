<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ExpireCheckout;
use App\Enums\PaymentStatus;
use App\Exceptions\PayMongoRequestFailed;
use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

/**
 * Expires one of a user's pending checkouts on the queue with
 * ExpireCheckout, once they have started a newer one, so starting a
 * checkout never waits on PayMongo. Like ExpireCheckout, it confirms a
 * checkout that was paid after all instead of expiring it.
 *
 * It is best effort and tried once: when PayMongo can't be reached, the
 * checkout stays pending, for the next start or the reconciliation to
 * settle. It carries the payment's id rather than the model, so a payment
 * deleted meanwhile (with its user) is skipped, and one already paid or
 * expired is left alone without asking PayMongo. It is unique per payment
 * while queued or running, and its lock lasts an hour at most.
 *
 * Its requests take at most three of PayMongo's 10-second timeouts (read,
 * expire, read again), so it ends well inside the worker's 60 seconds and
 * Laravel Cloud's 90-second Flex limit. Exceptions record no arguments
 * while it runs: the worker stops a job that takes too long wherever it
 * is, perhaps reading PayMongo's answer, and the failed job keeps that
 * exception's trace.
 */
#[Tries(1)]
#[Timeout(self::TIMEOUT)]
#[FailOnTimeout]
#[UniqueFor(self::UNIQUE_FOR)]
final class ExpireStaleCheckout implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * How long it may run, in seconds.
     */
    public const int TIMEOUT = 60;

    /**
     * How long the lock that keeps it unique lasts at most, in seconds.
     */
    public const int UNIQUE_FOR = 60 * 60;

    public function __construct(public readonly int $paymentId) {}

    public function uniqueId(): string
    {
        return (string) $this->paymentId;
    }

    public function handle(ExpireCheckout $expireCheckout): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if (! $payment instanceof Payment || $payment->status !== PaymentStatus::Pending) {
            return;
        }

        $recordedArguments = ini_set('zend.exception_ignore_args', '1');

        try {
            $expireCheckout->handle($payment);
        } catch (PayMongoRequestFailed) {
            // Best effort: it stays pending, for the next start or the reconciliation.
        } finally {
            if (is_string($recordedArguments)) {
                ini_set('zend.exception_ignore_args', $recordedArguments);
            }
        }
    }
}
