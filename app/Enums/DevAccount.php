<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;

/**
 * The two seeded users the local-only dev sign-in signs in as. Their GitHub ids
 * are negative, so they can never match a real GitHub account.
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

    /**
     * The profile the seeder gives this user.
     *
     * @return array{name: string, email: string, github_login: string, avatar_url: null}
     */
    public function profile(): array
    {
        return match ($this) {
            self::Dev => [
                'name' => 'Dev User',
                'email' => 'dev@example.com',
                'github_login' => 'dev-user',
                'avatar_url' => null,
            ],
            self::Second => [
                'name' => 'Second User',
                'email' => 'second@example.com',
                'github_login' => 'second-user',
                'avatar_url' => null,
            ],
        };
    }

    public function user(): ?User
    {
        return User::query()->where('github_id', $this->githubId())->first();
    }
}
