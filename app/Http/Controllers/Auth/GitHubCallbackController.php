<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\SyncGitHubUser;
use App\Http\Controllers\Controller;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * Signs in the user GitHub sends back, creating their account on first sign-in.
 */
class GitHubCallbackController extends Controller
{
    public function __invoke(Request $request, SyncGitHubUser $syncGitHubUser): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->backToSignIn(__('GitHub sign-in was cancelled. Sign in again whenever you like.'));
        }

        try {
            $githubUser = Socialite::driver('github')->user();
        } catch (InvalidStateException|GuzzleException $exception) {
            Log::warning('GitHub sign-in did not complete.', ['reason' => $exception::class]);

            return $this->backToSignIn(__("GitHub sign-in didn't complete. Please try again."));
        }

        Auth::login($syncGitHubUser->handle($githubUser), remember: true);

        $request->session()->regenerate();

        return redirect()->intended(route('stars.index'));
    }

    private function backToSignIn(string $message): RedirectResponse
    {
        return to_route('auth.sign-in')->with('toast', ['variant' => 'danger', 'text' => $message]);
    }
}
