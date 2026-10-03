<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Nexus couldn't get what it asked PayMongo for, or payments aren't set up.
 *
 * The message is Nexus's own and safe to show the user. Nothing PayMongo
 * sent is in it, and no exception is chained to it: the HTTP client's own
 * exceptions quote the response body.
 */
final class PayMongoRequestFailed extends RuntimeException
{
    /**
     * Payments aren't set up on this Nexus: it has no PayMongo secret key.
     */
    public static function notSetUp(): self
    {
        return new self(__('Payments aren\'t set up on this Nexus yet.'));
    }

    /**
     * Creating a checkout session failed.
     */
    public static function checkoutNotStarted(): self
    {
        return new self(__('PayMongo couldn\'t start the checkout. Try again in a minute.'));
    }

    /**
     * Reading or expiring a checkout session failed.
     */
    public static function checkoutNotRead(): self
    {
        return new self(__('Nexus couldn\'t check the payment with PayMongo just now. Try again in a minute.'));
    }
}
