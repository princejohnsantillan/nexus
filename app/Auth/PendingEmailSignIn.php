<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The address a guest asked for a sign-in code for, kept in their session
 * between the sign-in page and the page where they enter the code, so that
 * page survives a reload. It is forgotten once they sign in.
 */
final class PendingEmailSignIn
{
    private const string SESSION_KEY = 'email_sign_in.address';

    public static function remember(string $email): void
    {
        session()->put(self::SESSION_KEY, EmailCodes::address($email));
    }

    public static function address(): ?string
    {
        $email = session()->get(self::SESSION_KEY);

        return is_string($email) && $email !== '' ? $email : null;
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
