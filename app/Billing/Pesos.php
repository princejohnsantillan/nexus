<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Amounts in Philippine pesos, stored as whole centavos (₱499.00 is 49900),
 * as PayMongo counts them.
 */
final class Pesos
{
    /**
     * The amount to the nearest peso, as prices read: "₱4,999", "₱417".
     */
    public static function rounded(int $centavos): string
    {
        return '₱'.number_format($centavos / 100);
    }

    /**
     * The amount with its centavos, as a receipt reads: "₱4,999.00".
     */
    public static function exact(int $centavos): string
    {
        return '₱'.number_format($centavos / 100, 2);
    }
}
