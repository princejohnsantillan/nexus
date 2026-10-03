<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\AddSignInIdentity;
use App\Actions\SyncGoogleUser;
use App\Auth\GoogleAccount;
use App\Enums\IdentityProvider;
use App\Exceptions\IdentityBelongsToAnotherUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use UnexpectedValueException;

/**
 * Where Google sends the person back, for both ways it is used: a guest
 * signing in, which creates their account the first time, and a signed-in
 * user adding Google from Settings, which the session remembers across the
 * round trip. One callback URL serves both.
 */
class GoogleCallbackController extends Controller
{
    /**
     * The session key holding the id of the signed-in user who is adding
     * Google from Settings.
     */
    public const string ADDING_FOR_USER = 'google_sign_in.adding_for_user';

    public function __invoke(Request $request, SyncGoogleUser $syncGoogleUser, AddSignInIdentity $addSignInIdentity): RedirectResponse
    {
        $addingForUser = $request->session()->pull(self::ADDING_FOR_USER);
        $user = $request->user();

        if ($user instanceof User) {
            return $addingForUser === $user->id
                ? $this->addTo($user, $request, $addSignInIdentity)
                : to_route('stars.index');
        }

        return $this->signIn($request, $syncGoogleUser);
    }

    private function signIn(Request $request, SyncGoogleUser $syncGoogleUser): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->backTo('auth.sign-in', 'danger', __('Google sign-in was cancelled. Sign in again whenever you like.'));
        }

        $account = $this->googleAccount();

        if (! $account instanceof GoogleAccount) {
            return $this->backTo('auth.sign-in', 'danger', __("Google sign-in didn't complete. Please try again."));
        }

        Auth::login($syncGoogleUser->handle($account), remember: true);

        $request->session()->regenerate();

        return redirect()->intended(route('stars.index'));
    }

    private function addTo(User $user, Request $request, AddSignInIdentity $addSignInIdentity): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->backTo('settings.index', 'warning', __('Adding Google was cancelled. Add it again whenever you like.'));
        }

        $account = $this->googleAccount();

        if (! $account instanceof GoogleAccount) {
            return $this->backTo('settings.index', 'danger', __("Adding Google didn't complete. Please try again."));
        }

        try {
            $identity = $addSignInIdentity->handle($user, IdentityProvider::Google, $account->id, $account->email);
        } catch (IdentityBelongsToAnotherUser) {
            return $this->backTo('settings.index', 'danger', __('The Google account :email already signs in to another Nexus account. To add it here, sign in with it and remove it from that account first.', ['email' => $account->email]));
        }

        return $identity->wasRecentlyCreated
            ? $this->backTo('settings.index', 'success', __('Google added. You can now sign in with :email.', ['email' => $account->email]))
            : $this->backTo('settings.index', 'success', __(':email is already one of your sign-in methods.', ['email' => $account->email]));
    }

    /**
     * The Google account Google sent back, or null when the sign-in didn't
     * complete: a stale or forged state, Google unreachable, or a profile
     * without an id or email address.
     */
    private function googleAccount(): ?GoogleAccount
    {
        try {
            return GoogleAccount::from(Socialite::driver('google')->user());
        } catch (InvalidStateException|GuzzleException|UnexpectedValueException $exception) {
            Log::warning('Google sign-in did not complete.', ['reason' => $exception::class]);

            return null;
        }
    }

    private function backTo(string $route, string $variant, string $message): RedirectResponse
    {
        return to_route($route)->with('toast', ['variant' => $variant, 'text' => $message]);
    }
}
