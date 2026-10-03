<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\IdentityProvider;
use RuntimeException;

/**
 * A sign-in identity can't be added to a user because another user signs in
 * with it. Each provider's account belongs to one user only.
 *
 * The message is Nexus's own and safe to show; callers usually word their
 * own for the provider.
 */
final class IdentityBelongsToAnotherUser extends RuntimeException
{
    public function __construct(public readonly IdentityProvider $provider)
    {
        parent::__construct(__('That account already signs in to another Nexus account.'));
    }
}
