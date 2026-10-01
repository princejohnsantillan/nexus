<?php

declare(strict_types=1);

namespace App\Auth;

use Laravel\Socialite\Two\GithubProvider;

/**
 * Socialite's GitHub driver with the scopes Nexus signs in with: the profile and
 * the email addresses, so a primary email still arrives when the profile hides it.
 */
class GitHubSignInProvider extends GithubProvider
{
    /**
     * The scopes being requested.
     *
     * @var list<string>
     */
    protected $scopes = ['read:user', 'user:email'];
}
