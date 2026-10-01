<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * Sends the visitor to GitHub to sign in.
 */
class GitHubRedirectController extends Controller
{
    public function __invoke(): RedirectResponse|SymfonyRedirectResponse
    {
        if (blank(config('services.github.client_id'))) {
            return to_route('home')->with('toast', [
                'variant' => 'danger',
                'text' => __("GitHub sign-in isn't set up on this Nexus yet."),
            ]);
        }

        return Socialite::driver('github')->redirect();
    }
}
