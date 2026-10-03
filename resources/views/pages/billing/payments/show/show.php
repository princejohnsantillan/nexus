<?php

declare(strict_types=1);

use App\Actions\ConfirmPayment;
use App\Enums\PaymentStatus;
use App\Exceptions\PayMongoRequestFailed;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Where PayMongo sends the user back after paying (boards P5b and P5). It
 * asks PayMongo itself, so it works without a webhook: while the payment is
 * pending it checks again every 2 seconds for CHECK_SECONDS, and once it is
 * paid it shows the receipt. Revisiting it never applies a payment twice.
 */
return new #[Title('Payment')] class extends Component
{
    /**
     * How long the page keeps checking with PayMongo on its own, in seconds.
     */
    private const int CHECK_SECONDS = 120;

    #[Locked]
    public Payment $payment;

    /**
     * When the page started checking, as a Unix timestamp.
     */
    #[Locked]
    public int $checkingSince = 0;

    public function mount(Payment $payment, ConfirmPayment $confirmPayment): void
    {
        $this->payment = $payment;
        $this->checkingSince = now()->getTimestamp();

        // The layout around the page (the sidebar's plan card) reads the signed-in user, loaded before Pro changed.
        if ($this->confirm($confirmPayment)) {
            Auth::user()?->refresh();
        }
    }

    /**
     * Check again, every 2 seconds while pending. Once the payment is paid
     * the page is loaded afresh, so the sidebar shows Pro too.
     */
    public function check(ConfirmPayment $confirmPayment): void
    {
        if ($this->confirm($confirmPayment)) {
            $this->redirectRoute('billing.payments.show', $this->payment, navigate: true);
        }
    }

    /**
     * Start checking again after the page stopped.
     */
    public function checkAgain(ConfirmPayment $confirmPayment): void
    {
        $this->checkingSince = now()->getTimestamp();
        unset($this->isChecking, $this->state);

        $this->check($confirmPayment);
    }

    /**
     * What the page shows: `paid` (P5), `confirming` while it checks (P5b),
     * `waiting` once it has stopped checking, or `expired`.
     */
    #[Computed]
    public function state(): string
    {
        return match (true) {
            $this->payment->status === PaymentStatus::Paid => 'paid',
            $this->payment->status === PaymentStatus::Expired => 'expired',
            $this->isChecking => 'confirming',
            default => 'waiting',
        };
    }

    /**
     * Whether the page is still checking with PayMongo on its own.
     */
    #[Computed]
    public function isChecking(): bool
    {
        return now()->getTimestamp() - $this->checkingSince < self::CHECK_SECONDS;
    }

    /**
     * Ask PayMongo whether the payment went through, while it is pending
     * and the page is still checking, and say whether it was paid just now.
     * PayMongo not answering leaves it pending for the next check.
     */
    private function confirm(ConfirmPayment $confirmPayment): bool
    {
        if ($this->payment->status !== PaymentStatus::Pending || ! $this->isChecking) {
            return false;
        }

        try {
            $this->payment = $confirmPayment->handle($this->payment);
        } catch (PayMongoRequestFailed) {
            return false;
        }

        unset($this->state);

        return $this->payment->status === PaymentStatus::Paid;
    }
};
