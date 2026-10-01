<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\DevAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs in as a seeded user without a GitHub OAuth app. It is a plain link, so
 * it also works from the address bar and from curl; outside the local
 * environment, or with its flag off, it is a 404.
 */
class DevSignInController extends Controller
{
    public function __invoke(Request $request, DevAccount $account): RedirectResponse
    {
        abort_unless(DevAccount::signInIsEnabled(), 404);

        $user = $account->user();

        if (! $user instanceof User) {
            return to_route('home')->with('toast', [
                'variant' => 'warning',
                'text' => __('Run php artisan db:seed to create the dev users, then sign in again.'),
            ]);
        }

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        return redirect()->intended(route('stars.index'));
    }
}
