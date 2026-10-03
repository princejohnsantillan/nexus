<?php

declare(strict_types=1);

namespace App\Billing;

use Carbon\CarbonImmutable;

/**
 * One payment PayMongo made on a checkout session, as far as Nexus needs
 * it: whether it is paid, what for, how, and where the receipt went.
 */
final readonly class CheckoutPayment
{
    public function __construct(
        public string $id,
        public string $status,
        public int $amount,
        public string $currency,
        public ?string $method = null,
        public ?string $cardBrand = null,
        public ?string $cardLast4 = null,
        public ?CarbonImmutable $paidAt = null,
        public ?string $email = null,
    ) {}

    /**
     * The payment from a session's `payments` list, or null when it lacks
     * what every payment has.
     *
     * @param  array<mixed>  $payment
     */
    public static function fromArray(array $payment): ?self
    {
        $attributes = $payment['attributes'] ?? null;

        if (! is_string($payment['id'] ?? null) || ! is_array($attributes)) {
            return null;
        }

        $status = $attributes['status'] ?? null;
        $amount = $attributes['amount'] ?? null;
        $currency = $attributes['currency'] ?? null;

        if (! is_string($status) || ! is_int($amount) || ! is_string($currency)) {
            return null;
        }

        $source = is_array($attributes['source'] ?? null) ? $attributes['source'] : [];
        $billing = is_array($attributes['billing'] ?? null) ? $attributes['billing'] : [];
        $paidAt = $attributes['paid_at'] ?? null;

        return new self(
            id: $payment['id'],
            status: $status,
            amount: $amount,
            currency: $currency,
            method: self::text($source['type'] ?? null),
            cardBrand: self::text($source['brand'] ?? null),
            cardLast4: self::text($source['last4'] ?? null),
            paidAt: is_int($paidAt) ? CarbonImmutable::createFromTimestamp($paidAt) : null,
            email: self::text($billing['email'] ?? null),
        );
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
