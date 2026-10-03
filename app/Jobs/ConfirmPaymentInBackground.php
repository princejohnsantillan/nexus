<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ConfirmPayment;
use App\Enums\PaymentStatus;
use App\Exceptions\PayMongoRequestFailed;
use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;

/**
 * Confirms a payment on the queue with ConfirmPayment, which asks PayMongo
 * how its checkout stands, so PayMongo's webhook can answer straight away.
 *
 * It carries the payment's id rather than the model, so a payment deleted
 * meanwhile (with its user) is skipped, and one already paid or expired is
 * left alone without asking PayMongo. When PayMongo can't be asked, it
 * tries again later, TRIES times in all; after that the payment stays
 * pending and the scheduled reconciliation confirms it. As with every
 * PayMongo request, only the request's name and HTTP status are logged.
 * It is unique per payment while queued, waiting to try again or running,
 * so a delivery PayMongo repeats meanwhile queues nothing more; its lock
 * lasts an hour at most.
 *
 * Its one request takes at most PayMongo's 10-second timeout, well inside
 * its own TIMEOUT and Laravel Cloud's 90-second Flex limit. Exceptions
 * record no arguments while it runs: the worker stops a job that takes too
 * long wherever it is, perhaps reading PayMongo's answer, and the failed
 * job keeps that exception's trace.
 */
#[Tries(self::TRIES)]
#[Timeout(self::TIMEOUT)]
#[UniqueFor(self::UNIQUE_FOR)]
final class ConfirmPaymentInBackground implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * How many times it asks PayMongo at most.
     */
    public const int TRIES = 3;

    /**
     * Seconds to wait before asking again, after each failed try.
     *
     * @var list<int>
     */
    public const array RETRY_AFTER = [30, 120];

    /**
     * How long each try may run, in seconds.
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

    public function handle(ConfirmPayment $confirmPayment): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if (! $payment instanceof Payment || $payment->status !== PaymentStatus::Pending) {
            return;
        }

        $recordedArguments = ini_set('zend.exception_ignore_args', '1');

        try {
            $confirmPayment->handle($payment);
        } catch (PayMongoRequestFailed) {
            if ($this->attempts() < self::TRIES) {
                $this->release(self::RETRY_AFTER[$this->attempts() - 1] ?? 120);
            }
        } finally {
            if (is_string($recordedArguments)) {
                ini_set('zend.exception_ignore_args', $recordedArguments);
            }
        }
    }
}
