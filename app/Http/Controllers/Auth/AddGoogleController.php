<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\GoogleSignInProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Sends a signed-in user to Google to add it as another way to sign in. The
 * session remembers who is adding it, so Google's callback adds the account
 * to them instead of signing in with it.
 */
class AddGoogleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|SymfonyRedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 404);

        if (! GoogleSignInProvider::isConfigured()) {
            return to_route('settings.index')->with('toast', [
                'variant' => 'danger',
                'text' => __("Google sign-in isn't set up on this Nexus."),
            ]);
        }

        $request->session()->put(GoogleCallbackController::ADDING_FOR_USER, $user->id);

        return Socialite::driver('google')->redirect();
    }
}
