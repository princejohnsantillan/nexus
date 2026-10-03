<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\WebhookEvent;
use App\Billing\WebhookSignature;
use App\Enums\PaymentStatus;
use App\Jobs\ConfirmPaymentInBackground;
use App\Models\Payment;

/**
 * Take a delivery to PayMongo's webhook. Only one signed with the webhook
 * secret (`services.paymongo.webhook_secret`), in its event's own mode, is
 * PayMongo's; without a secret, none is.
 *
 * A `checkout_session.payment.paid` event for a pending payment queues
 * ConfirmPaymentInBackground, which asks PayMongo whether the checkout was
 * paid: the event's word is never taken for it. The payment is found by the
 * checkout session's id, or by its reference number. Any other event, and
 * a checkout Nexus doesn't know or has settled already, is taken and
 * ignored. Nothing in a delivery is logged.
 */
final readonly class ReceivePayMongoWebhook
{
    /**
     * @param  string  $payload  The delivery's raw body.
     * @param  string|null  $signature  Its `Paymongo-Signature` header.
     * @return bool Whether the delivery is PayMongo's. One that isn't changes nothing.
     */
    public function handle(string $payload, ?string $signature): bool
    {
        $secret = config('services.paymongo.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $event = WebhookEvent::fromPayload($payload);
        $signature = WebhookSignature::fromHeader($signature);

        if (! $event instanceof WebhookEvent || ! $signature instanceof WebhookSignature || ! $signature->signs($payload, $secret, $event->livemode)) {
            return false;
        }

        $payment = $event->isCheckoutPaid() ? $this->paymentFor($event) : null;

        if ($payment?->status === PaymentStatus::Pending) {
            ConfirmPaymentInBackground::dispatch($payment->id);
        }

        return true;
    }

    private function paymentFor(WebhookEvent $event): ?Payment
    {
        if ($event->checkoutSessionId !== null) {
            $payment = Payment::query()->where('checkout_session_id', $event->checkoutSessionId)->first();

            if ($payment instanceof Payment) {
                return $payment;
            }
        }

        return $event->referenceNumber !== null
            ? Payment::query()->where('reference', $event->referenceNumber)->first()
            : null;
    }
}
