<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Sign-in with GitHub or Google. Nexus stores no passwords; the provider
 * handles them and any second factor.
 */
class SocialLoginController extends Controller
{
    public function redirect(string $provider): SymfonyRedirect
    {
        $this->ensureSupported($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->ensureSupported($provider);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (Throwable) {
            return $this->failed('Sign-in was cancelled or did not complete. Please try again.');
        }

        $email = strtolower((string) $socialUser->getEmail());

        if ($email === '' || ! $this->emailIsVerified($provider, $socialUser)) {
            return $this->failed('Nexus needs a verified email address from your account to sign you in.');
        }

        $user = DB::transaction(fn (): User => $this->findOrCreateUser($provider, $socialUser, $email));

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(Filament::getPanel('app')->getUrl());
    }

    /**
     * Accounts are matched by provider id first, then by verified email, so
     * signing in with GitHub and Google lands in the same account.
     */
    protected function findOrCreateUser(string $provider, SocialUser $socialUser, string $email): User
    {
        $account = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', (string) $socialUser->getId())
            ->first();

        if ($account !== null) {
            return $account->user;
        }

        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => $socialUser->getName() ?: $socialUser->getNickname() ?: $email,
            'avatar_url' => $socialUser->getAvatar(),
            'email_verified_at' => now(),
        ]);

        $user->socialAccounts()->create([
            'provider' => $provider,
            'provider_user_id' => (string) $socialUser->getId(),
        ]);

        return $user;
    }

    /**
     * GitHub only returns an email it has verified. Google says so explicitly.
     */
    protected function emailIsVerified(string $provider, SocialUser $socialUser): bool
    {
        if ($provider !== 'google') {
            return true;
        }

        $raw = method_exists($socialUser, 'getRaw') ? $socialUser->getRaw() : [];

        return filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOL);
    }

    protected function ensureSupported(string $provider): void
    {
        abort_unless(in_array($provider, config('nexus.auth.providers'), true), 404);
    }

    protected function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('error', $message);
    }
}
