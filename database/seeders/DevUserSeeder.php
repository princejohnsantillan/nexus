<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DevAccount;
use App\Enums\IdentityProvider;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the users the local-only dev sign-in signs in as, each with their
 * GitHub identity. Safe to run again: it restores a deleted user and resets
 * a changed profile.
 */
class DevUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (DevAccount::cases() as $account) {
            $user = $account->user() ?? new User;

            $user->fill($account->profile())->save();

            $user->signInIdentities()->updateOrCreate(
                ['provider' => IdentityProvider::GitHub, 'provider_user_id' => (string) $account->githubId()],
                ['login' => $account->githubLogin()],
            );
        }
    }
}
