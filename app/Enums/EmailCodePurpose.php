<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a one-time email code is for. A code only ever works for the purpose
 * it was sent for, so a code sent to add an address in Settings can't sign
 * anyone in, and a sign-in code can't add an address.
 */
enum EmailCodePurpose: string
{
    /** Signing in with the address, from the sign-in page. */
    case SignIn = 'sign-in';

    /** Adding the address to the signed-in user's sign-in methods, from Settings. */
    case AddToAccount = 'add-to-account';
}
