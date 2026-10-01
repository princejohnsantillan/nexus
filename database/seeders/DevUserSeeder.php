<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DevAccount;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the users the local-only dev sign-in signs in as. Safe to run again:
 * it restores a deleted user and resets a changed profile.
 */
class DevUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (DevAccount::cases() as $account) {
            User::query()->updateOrCreate(
                ['github_id' => $account->githubId()],
                $account->profile(),
            );
        }
    }
}
