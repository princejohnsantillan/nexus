<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\CheckoutSession;
use App\Billing\PayMongo;
use App\Enums\BillingPeriod;
use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Exceptions\PayMongoRequestFailed;
use App\Models\Payment;
use App\Models\User;

/**
 * Start paying for a month or a year of Pro: a pending Payment and a
 * PayMongo checkout session for it, whose `checkout_url` the user is sent
 * to. What it costs comes from the plan, never from the request.
 */
final readonly class StartCheckout
{
    /**
     * How many of the user's older pending checkouts one start expires.
     * Each start expires the ones before it, so there are seldom more.
     */
    private const int EXPIRES_AT_MOST = 5;

    public function __construct(
        private PayMongo $payMongo,
        private ExpireCheckout $expireCheckout,
    ) {}

    /**
     * Create the payment and its checkout session, and return the session,
     * whose URL the user pays at: one line item, "Nexus Pro · Monthly" or
     * "· Yearly", in pesos, paid with the configured methods.
     * PayMongo sends the user back to the payment's return page, or to the
     * Upgrade page when they cancel, and emails them a receipt. The user's
     * older pending checkouts are expired afterwards, as far as PayMongo
     * lets them be. When the session can't be created, the payment is
     * deleted again.
     *
     * @throws PayMongoRequestFailed when payments aren't set up, or PayMongo couldn't start the checkout
     */
    public function handle(User $user, BillingPeriod $period): CheckoutSession
    {
        if (! PayMongo::isSetUp()) {
            throw PayMongoRequestFailed::notSetUp();
        }

        $olderCheckouts = $user->payments()
            ->where('status', PaymentStatus::Pending)
            ->latest('id')
            ->limit(self::EXPIRES_AT_MOST)
            ->get();

        $payment = $user->payments()->create([
            'period' => $period,
            'amount' => Plan::Pro->price($period),
            'currency' => 'PHP',
            'status' => PaymentStatus::Pending,
        ]);

        try {
            $session = $this->payMongo->createCheckoutSession($this->sessionFor($user, $payment), $payment->reference);
        } catch (PayMongoRequestFailed $failed) {
            $payment->delete();

            throw $failed;
        }

        $payment->forceFill([
            'checkout_session_id' => $session->id,
            'checkout_url' => $session->checkoutUrl,
        ])->save();

        foreach ($olderCheckouts as $olderCheckout) {
            try {
                $this->expireCheckout->handle($olderCheckout);
            } catch (PayMongoRequestFailed) {
                // Best effort: it stays pending, and is tried again on the next start.
            }
        }

        return $session;
    }

    /**
     * The checkout session's attributes, as PayMongo's API names them.
     *
     * @return array<string, mixed>
     */
    private function sessionFor(User $user, Payment $payment): array
    {
        $billing = array_filter(['name' => $user->name, 'email' => $user->email], fn (?string $value): bool => $value !== null && $value !== '');

        return [
            'line_items' => [[
                'name' => $payment->itemName(),
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'quantity' => 1,
            ]],
            'payment_method_types' => PayMongo::paymentMethods(),
            'success_url' => route('billing.payments.show', $payment),
            'cancel_url' => route('billing.upgrade', ['period' => $payment->period->value, 'cancelled' => 1, 'payment' => $payment->reference]),
            'reference_number' => $payment->reference,
            'description' => $payment->period === BillingPeriod::Year
                ? __('A year of Nexus Pro. It doesn\'t renew on its own.')
                : __('A month of Nexus Pro. It doesn\'t renew on its own.'),
            'send_email_receipt' => true,
            ...($billing === [] ? [] : ['billing' => $billing]),
        ];
    }
}
