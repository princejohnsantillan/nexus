<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingPeriod;
use App\Enums\PaymentStatus;
use App\Enums\Plan;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state: a pending checkout for a year of Pro.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sessionId = Str::lower(Str::random(24));

        return [
            'user_id' => User::factory(),
            'period' => BillingPeriod::Year,
            'amount' => Plan::Pro->price(BillingPeriod::Year),
            'currency' => 'PHP',
            'status' => PaymentStatus::Pending,
            'checkout_session_id' => "cs_{$sessionId}",
            'checkout_url' => "https://checkout.paymongo.com/{$sessionId}",
        ];
    }

    /**
     * Indicate that the payment is for a month of Pro, at its price.
     */
    public function monthly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'period' => BillingPeriod::Month,
            'amount' => Plan::Pro->price(BillingPeriod::Month),
        ]);
    }

    /**
     * Indicate that the payment was paid with this method at this moment (now
     * unless given), and bought the period from then.
     */
    public function paid(string $method = 'gcash', ?CarbonImmutable $at = null): static
    {
        return $this->state(function (array $attributes) use ($method, $at): array {
            $paidAt = $at ?? CarbonImmutable::now();
            $period = $attributes['period'] instanceof BillingPeriod ? $attributes['period'] : BillingPeriod::Year;

            return [
                'status' => PaymentStatus::Paid,
                'paymongo_payment_id' => 'pay_'.Str::random(24),
                'method' => $method,
                'paid_at' => $paidAt,
                'confirmed_at' => $paidAt,
                'pro_from' => $paidAt,
                'pro_until' => $period->after($paidAt),
            ];
        });
    }

    /**
     * Indicate that the payment was paid by card, at this moment (now unless given).
     */
    public function paidByCard(string $brand = 'visa', string $last4 = '4242', ?CarbonImmutable $at = null): static
    {
        return $this->paid('card', $at)->state(fn (array $attributes): array => [
            'card_brand' => $brand,
            'card_last4' => $last4,
        ]);
    }

    /**
     * Indicate that the checkout expired without a payment.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Expired,
        ]);
    }
}
