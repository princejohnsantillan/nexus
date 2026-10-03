<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\CheckoutPayment;
use App\Billing\CheckoutSession;
use App\Billing\PayMongo;
use App\Enums\PaymentStatus;
use App\Exceptions\PayMongoRequestFailed;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one way a payment is confirmed, whoever asks: the return page, the
 * webhook or the reconciliation. It asks PayMongo itself, so a payment is
 * never taken on the caller's word, and it is idempotent: a payment is
 * applied to the user's Pro once, however often it is confirmed.
 */
final readonly class ConfirmPayment
{
    public function __construct(private PayMongo $payMongo) {}

    /**
     * Ask PayMongo how the payment's checkout stands, and record it (see
     * record()). A payment that is already paid or expired is returned as it
     * is, without asking.
     *
     * @throws PayMongoRequestFailed when PayMongo can't be asked
     */
    public function handle(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::Pending || $payment->checkout_session_id === null) {
            return $payment;
        }

        return $this->record($payment, $this->payMongo->checkoutSession($payment->checkout_session_id));
    }

    /**
     * Record what PayMongo just said about the payment's checkout session.
     * A paid payment on it makes the payment paid and moves the user's
     * `pro_until` forward by its period, from now or from when their Pro
     * ends, whichever is later. A session that expired unpaid makes it
     * expired. Anything else leaves it pending.
     */
    public function record(Payment $payment, CheckoutSession $session): Payment
    {
        $paid = $session->paidPayment();

        if ($paid instanceof CheckoutPayment) {
            return $this->markPaid($payment, $paid);
        }

        return $session->hasExpired() ? $this->markExpired($payment) : $payment;
    }

    /**
     * Mark the payment paid and extend the user's Pro, in one transaction
     * holding the user's row and then the payment's, so two confirmations
     * at once apply it once. A payment expired here but paid on PayMongo
     * meanwhile is still applied: the money was taken.
     */
    private function markPaid(Payment $payment, CheckoutPayment $paid): Payment
    {
        return DB::transaction(function () use ($payment, $paid): Payment {
            $user = User::query()->lockForUpdate()->findOrFail($payment->user_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status === PaymentStatus::Paid) {
                return $payment;
            }

            $proFrom = $user->nextProStart();
            $proUntil = $user->proUntilAfterPaying($payment->period);
            $isCard = $paid->method === 'card';

            $payment->forceFill([
                'status' => PaymentStatus::Paid,
                'paymongo_payment_id' => $paid->id,
                'method' => $paid->method,
                'card_brand' => $isCard ? $paid->cardBrand : null,
                'card_last4' => $isCard ? $paid->cardLast4 : null,
                'receipt_email' => $paid->email,
                'paid_at' => $paid->paidAt ?? CarbonImmutable::now(),
                // Never later than the period's start, so a period that starts now reads as not extending Pro.
                'confirmed_at' => CarbonImmutable::now()->min($proFrom),
                'pro_from' => $proFrom,
                'pro_until' => $proUntil,
            ])->save();

            $user->forceFill(['pro_until' => $proUntil])->save();

            return $payment;
        });
    }

    /**
     * Mark the payment expired, unless it was paid meanwhile.
     */
    private function markExpired(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status === PaymentStatus::Pending) {
                $payment->forceFill(['status' => PaymentStatus::Expired])->save();
            }

            return $payment;
        });
    }
}
