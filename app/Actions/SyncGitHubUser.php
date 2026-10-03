<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\IdentityProvider;
use App\Models\SignInIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as GitHubUser;
use UnexpectedValueException;

class SyncGitHubUser
{
    /**
     * Find the user by their GitHub identity, or create a new account with
     * it, and refresh their profile and login from GitHub. Matching on the
     * GitHub id means a changed login or email never creates a second
     * account, and an email address never matches anyone. GitHub may hide
     * the email; the account works without one.
     *
     * The user's old GitHub columns are kept up to date too, for code that
     * still reads them. A user the previous release signed up while this one
     * was being deployed has a GitHub id there but no identity yet, so they
     * are found by it and given one.
     */
    public function handle(GitHubUser $githubUser): User
    {
        $githubId = filter_var($githubUser->getId(), FILTER_VALIDATE_INT);
        $login = $githubUser->getNickname();

        if ($githubId === false || $login === null || $login === '') {
            throw new UnexpectedValueException('GitHub returned a profile without an id or a login.');
        }

        $name = $githubUser->getName();

        return DB::transaction(function () use ($githubUser, $githubId, $login, $name): User {
            $user = SignInIdentity::findFor(IdentityProvider::GitHub, (string) $githubId)->user
                ?? User::query()->where('github_id', $githubId)->first()
                ?? new User;

            $user->fill([
                'name' => $name === null || $name === '' ? $login : Str::limit($name, 255, ''),
                'email' => $githubUser->getEmail(),
                'avatar_url' => $githubUser->getAvatar(),
                'github_id' => $githubId,
                'github_login' => $login,
            ])->save();

            $user->signInIdentities()->updateOrCreate(
                ['provider' => IdentityProvider::GitHub, 'provider_user_id' => (string) $githubId],
                ['login' => $login],
            );

            return $user;
        });
    }
}
