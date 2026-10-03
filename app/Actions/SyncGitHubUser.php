<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\IdentityProvider;
use App\Models\SignInIdentity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
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

        $profile = [
            'name' => $name === null || $name === '' ? $login : Str::limit($name, 255, ''),
            'email' => $githubUser->getEmail(),
            'avatar_url' => $githubUser->getAvatar(),
            'github_id' => $githubId,
            'github_login' => $login,
        ];

        $user = $this->find($githubId) ?? $this->create($githubId, $login, $profile);

        DB::transaction(function () use ($user, $githubId, $login, $profile): void {
            $user->fill($profile)->save();

            $user->signInIdentities()->updateOrCreate(
                ['provider' => IdentityProvider::GitHub, 'provider_user_id' => (string) $githubId],
                ['login' => $login],
            );
        });

        return $user;
    }

    private function find(int $githubId): ?User
    {
        return SignInIdentity::findFor(IdentityProvider::GitHub, (string) $githubId)->user
            ?? User::query()->where('github_id', $githubId)->first();
    }

    /**
     * Create the account and its GitHub identity together. Two first sign-ins
     * with the same GitHub account at once both get here; the second one's
     * insert breaks a unique index, and it signs in to the account the first
     * one created. The insert runs in its own transaction (a savepoint inside
     * another), so on Postgres the failure doesn't spoil the rest.
     *
     * @param  array<string, mixed>  $profile
     */
    private function create(int $githubId, string $login, array $profile): User
    {
        try {
            return DB::transaction(function () use ($githubId, $login, $profile): User {
                $user = User::query()->create($profile);

                $user->signInIdentities()->create([
                    'provider' => IdentityProvider::GitHub,
                    'provider_user_id' => (string) $githubId,
                    'login' => $login,
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->find($githubId) ?? throw $exception;
        }
    }
}
