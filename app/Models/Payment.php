<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One checkout a user started on PayMongo for a month or a year of Pro
 * (App\Actions\StartCheckout), named by its public `reference`, a ULID
 * that is also PayMongo's `reference_number` and the route key of its
 * return page.
 *
 * It is pending until App\Actions\ConfirmPayment hears from PayMongo that
 * it was paid, when it records how (`method`, and the card's brand and
 * last 4) and the Pro period it bought (`pro_from` to `pro_until`), or that
 * it expired, when nothing was charged. The amount and period are set by
 * Nexus from the plan, never by the client. Deleting the user deletes their
 * payments.
 *
 * @property int $id
 * @property int $user_id
 * @property string $reference
 * @property BillingPeriod $period
 * @property int $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $checkout_session_id
 * @property string|null $checkout_url
 * @property string|null $paymongo_payment_id
 * @property string|null $method
 * @property string|null $card_brand
 * @property string|null $card_last4
 * @property string|null $receipt_email
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $pro_from
 * @property CarbonImmutable|null $pro_until
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 *
 * @method static \Database\Factories\PaymentFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment query()
 *
 * @mixin \Eloquent
 */
#[Fillable(['period', 'amount', 'currency', 'status'])]
#[RouteKey('reference')]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUlids;

    /**
     * PayMongo's names for the payment methods its checkout offers, as people
     * know them. A method missing here shows as PayMongo names it.
     *
     * @var array<string, string>
     */
    private const array METHOD_NAMES = [
        'card' => 'Card',
        'gcash' => 'GCash',
        'paymaya' => 'Maya',
        'qrph' => 'QR Ph',
        'grab_pay' => 'GrabPay',
    ];

    /**
     * Card brands as they're written, where capitalising PayMongo's name isn't enough.
     *
     * @var array<string, string>
     */
    private const array CARD_BRANDS = [
        'jcb' => 'JCB',
        'amex' => 'Amex',
        'american_express' => 'Amex',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => BillingPeriod::class,
            'amount' => 'integer',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'pro_from' => 'datetime',
            'pro_until' => 'datetime',
        ];
    }

    /**
     * The reference is generated as a ULID when the payment is created; the
     * id stays an ordinary number.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['reference'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What was bought, as the checkout's line item and the receipt name it:
     * "Nexus Pro · Monthly" or "Nexus Pro · Yearly".
     */
    public function itemName(): string
    {
        return __('Nexus Pro · :period', ['period' => $this->period->label()]);
    }

    /**
     * How it was paid, as people know it: "GCash", "Maya", "QR Ph", or a
     * card as "Visa ···· 4242". Null until PayMongo says.
     */
    public function methodLabel(): ?string
    {
        if ($this->method === null) {
            return null;
        }

        if ($this->method === 'card' && $this->card_brand !== null && $this->card_last4 !== null) {
            $brand = self::CARD_BRANDS[$this->card_brand] ?? Str::headline($this->card_brand);

            return "{$brand} ···· {$this->card_last4}";
        }

        return self::METHOD_NAMES[$this->method] ?? Str::headline($this->method);
    }

    /**
     * Whether paying added to Pro the user already had, rather than starting
     * it: the period bought began after the payment was confirmed.
     */
    public function extendedPro(): bool
    {
        return $this->pro_from !== null && $this->confirmed_at !== null && $this->pro_from->isAfter($this->confirmed_at);
    }
}
