<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\GoogleSignInProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Sends the visitor to Google to sign in.
 */
class GoogleRedirectController extends Controller
{
    public function __invoke(): RedirectResponse|SymfonyRedirectResponse
    {
        if (! GoogleSignInProvider::isConfigured()) {
            return to_route('auth.sign-in')->with('toast', [
                'variant' => 'danger',
                'text' => __("Google sign-in isn't set up on this Nexus."),
            ]);
        }

        return Socialite::driver('google')->redirect();
    }
}
