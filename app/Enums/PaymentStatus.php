<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a checkout for Pro stands: waiting on PayMongo, paid (Pro was
 * extended), or expired without a payment, so nothing was charged.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';

    case Paid = 'paid';

    case Expired = 'expired';
}
