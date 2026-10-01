<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as GitHubUser;
use UnexpectedValueException;

class SyncGitHubUser
{
    /**
     * Find the user by their GitHub id, or create them, and refresh their profile
     * from GitHub. Matching on the id means a changed login or email never creates
     * a second account. GitHub may hide the email; the account works without one.
     */
    public function handle(GitHubUser $githubUser): User
    {
        $githubId = filter_var($githubUser->getId(), FILTER_VALIDATE_INT);
        $login = $githubUser->getNickname();

        if ($githubId === false || $login === null || $login === '') {
            throw new UnexpectedValueException('GitHub returned a profile without an id or a login.');
        }

        $name = $githubUser->getName();

        return User::query()->updateOrCreate(['github_id' => $githubId], [
            'name' => $name === null || $name === '' ? $login : Str::limit($name, 255, ''),
            'email' => $githubUser->getEmail(),
            'github_login' => $login,
            'avatar_url' => $githubUser->getAvatar(),
        ]);
    }
}
