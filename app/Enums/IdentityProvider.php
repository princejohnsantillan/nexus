<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who vouches for a sign-in identity, and so what its provider user id is.
 */
enum IdentityProvider: string
{
    /** GitHub's numeric user id; the login is the GitHub login. */
    case GitHub = 'github';

    /** A one-time code sent to an email address; the id and the login are the address, in lower case. */
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => __('GitHub'),
            self::Email => __('Email'),
        };
    }
}
