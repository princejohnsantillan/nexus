<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * A PayMongo checkout session, as far as Nexus needs it: where to send the
 * user to pay, whether it has expired, and the payments made on it.
 *
 * Only a payment says whether the session was paid: PayMongo leaves a paid
 * session's own status `active`.
 */
final readonly class CheckoutSession
{
    /**
     * @param  list<CheckoutPayment>  $payments
     */
    public function __construct(
        public string $id,
        public string $checkoutUrl,
        public string $status,
        public array $payments = [],
    ) {}

    /**
     * The session in a PayMongo response's `data`, or null when it isn't
     * one. Creating a session answers with only its id and URL, so a
     * session without a status is taken as active.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $attributes = $data['attributes'] ?? null;

        if (! is_string($id) || preg_match('/\Acs_[A-Za-z0-9]+\z/', $id) !== 1 || ! is_array($attributes)) {
            return null;
        }

        $checkoutUrl = $attributes['checkout_url'] ?? null;
        $status = $attributes['status'] ?? 'active';
        $payments = is_array($attributes['payments'] ?? null) ? $attributes['payments'] : [];

        if (! is_string($checkoutUrl) || ! str_starts_with($checkoutUrl, 'https://') || ! is_string($status)) {
            return null;
        }

        return new self(
            id: $id,
            checkoutUrl: $checkoutUrl,
            status: $status,
            payments: array_values(array_filter(array_map(
                fn (mixed $payment): ?CheckoutPayment => is_array($payment) ? CheckoutPayment::fromArray($payment) : null,
                $payments,
            ))),
        );
    }

    /**
     * The payment that paid the session, if one has.
     */
    public function paidPayment(): ?CheckoutPayment
    {
        foreach ($this->payments as $payment) {
            if ($payment->isPaid()) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * Whether the session can no longer be paid. One that expired after
     * being paid still has its paid payment.
     */
    public function hasExpired(): bool
    {
        return $this->status === 'expired';
    }
}
