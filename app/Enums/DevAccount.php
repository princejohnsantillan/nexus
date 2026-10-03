<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\SignInIdentity;
use App\Models\User;

/**
 * The two seeded users the local-only dev sign-in signs in as. They sign in
 * with GitHub identities whose ids are negative, so they can never match a
 * real GitHub account.
 */
enum DevAccount: string
{
    case Dev = 'dev';
    case Second = 'second';

    /**
     * Dev sign-in exists only in the local environment, and only when its flag is on.
     */
    public static function signInIsEnabled(): bool
    {
        return app()->isLocal() && config()->boolean('nexus.dev_sign_in');
    }

    public function githubId(): int
    {
        return match ($this) {
            self::Dev => -1,
            self::Second => -2,
        };
    }

    public function githubLogin(): string
    {
        return match ($this) {
            self::Dev => 'dev-user',
            self::Second => 'second-user',
        };
    }

    /**
     * The profile the seeder gives this user.
     *
     * @return array{name: string, email: string, avatar_url: null, github_id: int, github_login: string}
     */
    public function profile(): array
    {
        return [
            ...match ($this) {
                self::Dev => ['name' => 'Dev User', 'email' => 'dev@example.com'],
                self::Second => ['name' => 'Second User', 'email' => 'second@example.com'],
            },
            'avatar_url' => null,
            'github_id' => $this->githubId(),
            'github_login' => $this->githubLogin(),
        ];
    }

    public function user(): ?User
    {
        return SignInIdentity::findFor(IdentityProvider::GitHub, (string) $this->githubId())?->user;
    }
}
