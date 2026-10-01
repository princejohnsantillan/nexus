<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class SignOut
{
    /**
     * Sign the current user out and start a fresh session, so nothing from the
     * signed-in session (including its CSRF token) can be reused.
     */
    public function handle(): void
    {
        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();
    }
}
