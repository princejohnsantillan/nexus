<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\PayMongo;
use App\Enums\PaymentStatus;
use App\Exceptions\PayMongoRequestFailed;
use App\Models\Payment;

/**
 * Close a pending checkout on PayMongo so it can't be paid any more: one
 * the user cancelled, or left behind by starting another.
 *
 * A checkout that was paid after all is confirmed, never expired. PayMongo
 * is asked first, and when it refuses to expire the session (it was paid
 * or expired meanwhile), the session is read again and recorded as it is.
 */
final readonly class ExpireCheckout
{
    public function __construct(
        private PayMongo $payMongo,
        private ConfirmPayment $confirmPayment,
    ) {}

    /**
     * @throws PayMongoRequestFailed when PayMongo can't be asked
     */
    public function handle(Payment $payment): Payment
    {
        $payment = $this->confirmPayment->handle($payment);

        if ($payment->status !== PaymentStatus::Pending || $payment->checkout_session_id === null) {
            return $payment;
        }

        try {
            $session = $this->payMongo->expireCheckoutSession($payment->checkout_session_id);
        } catch (PayMongoRequestFailed) {
            $session = $this->payMongo->checkoutSession($payment->checkout_session_id);
        }

        return $this->confirmPayment->record($payment, $session);
    }
}
