<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

class DeleteAccount
{
    public function __construct(private readonly SignOut $signOut) {}

    /**
     * Sign the user out and delete them. Everything a user owns cascades from the
     * users table, including their data key.
     *
     * Signing out comes first: it rewrites the user's remember token, and saving
     * that on an already deleted model would insert the user again.
     */
    public function handle(User $user): void
    {
        $this->signOut->handle();

        $user->delete();
    }
}
