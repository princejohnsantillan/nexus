<?php

declare(strict_types=1);

use App\Actions\ExpireCheckout;
use App\Actions\StartCheckout;
use App\Billing\PayMongo;
use App\Enums\BillingPeriod;
use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Exceptions\PayMongoRequestFailed;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Upgrade')] class extends Component
{
    /**
     * The period picked on the Monthly / Yearly picker: a BillingPeriod
     * value. Yearly unless the link says `?period=month`.
     */
    public string $period = BillingPeriod::Year->value;

    /**
     * Whether the user came back from a checkout they cancelled on PayMongo.
     */
    #[Locked]
    public bool $cancelled = false;

    public function mount(ExpireCheckout $expireCheckout): void
    {
        $period = request()->query('period');

        $this->period = ((is_string($period) ? BillingPeriod::tryFrom($period) : null) ?? BillingPeriod::Year)->value;
        $this->cancelled = request()->query('cancelled') === '1';

        if ($this->cancelled) {
            $this->expireCancelledCheckout($expireCheckout);
        }
    }

    /**
     * Start paying for Pro for the period picked, and send the browser to
     * PayMongo's checkout. The price comes from the plan, not the page.
     */
    public function continueToPayment(StartCheckout $startCheckout): void
    {
        try {
            $checkout = $startCheckout->handle($this->user, $this->billingPeriod);
        } catch (PayMongoRequestFailed $failed) {
            $this->addError('checkout', $failed->getMessage());

            return;
        }

        $this->redirect($checkout->checkoutUrl);
    }

    #[Computed]
    public function user(): User
    {
        return Auth::user() ?? throw new AuthenticationException;
    }

    /**
     * The period picked, or Yearly if the picker sent something else.
     */
    #[Computed]
    public function billingPeriod(): BillingPeriod
    {
        return BillingPeriod::tryFrom($this->period) ?? BillingPeriod::Year;
    }

    #[Computed]
    public function isPro(): bool
    {
        return $this->user->plan() === Plan::Pro;
    }

    /**
     * Whether this Nexus takes payments through PayMongo.
     */
    #[Computed]
    public function paymentsAreSetUp(): bool
    {
        return PayMongo::isSetUp();
    }

    /**
     * Expire the checkout the user cancelled, named by the link PayMongo
     * sent them back with, best effort. One that was paid after all goes
     * to its receipt instead.
     */
    private function expireCancelledCheckout(ExpireCheckout $expireCheckout): void
    {
        $reference = request()->query('payment');
        $payment = is_string($reference) ? $this->user->payments()->where('reference', $reference)->first() : null;

        if ($payment === null || ! PayMongo::isSetUp()) {
            return;
        }

        try {
            $payment = $expireCheckout->handle($payment);
        } catch (PayMongoRequestFailed) {
            return;
        }

        if ($payment->status === PaymentStatus::Paid) {
            $this->redirectRoute('billing.payments.show', $payment, navigate: true);
        }
    }
};
