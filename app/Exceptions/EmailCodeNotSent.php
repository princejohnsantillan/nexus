<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The email with a one-time code couldn't be sent, and the code was
 * withdrawn.
 *
 * The message is Nexus's own and safe to show the user. Nothing from the
 * failure is chained to it, since that failure's stack trace holds the
 * email and its code.
 */
final class EmailCodeNotSent extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('Nexus couldn\'t send the email just now. Try again in a minute.'));
    }
}
