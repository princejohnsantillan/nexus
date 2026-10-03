<?php

declare(strict_types=1);

namespace App\Actions;

use App\Auth\GoogleAccount;
use App\Enums\IdentityProvider;
use App\Models\SignInIdentity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class SyncGoogleUser
{
    /**
     * Find the user by their Google identity, or create a new account with
     * it, its name, email address and avatar taken from Google. Matching on
     * Google's subject id means a changed email address never creates a
     * second account, and an email address never matches anyone: the same
     * address on GitHub or an email sign-in may be someone else's.
     *
     * A returning user's identity gets the Google account's current email
     * address as its login. Their profile is left alone: it may come from
     * GitHub, which refreshes it on every sign-in.
     */
    public function handle(GoogleAccount $account): User
    {
        $identity = SignInIdentity::findFor(IdentityProvider::Google, $account->id);

        if (! $identity instanceof SignInIdentity) {
            return $this->create($account);
        }

        $identity->update(['login' => $account->email]);

        return $identity->user;
    }

    /**
     * Create the account and its Google identity together. Two first sign-ins
     * with the same Google account at once both get here; the second one's
     * insert breaks a unique index, and it signs in to the account the first
     * one created. The insert runs in its own transaction (a savepoint inside
     * another), so on Postgres the failure doesn't spoil the rest.
     */
    private function create(GoogleAccount $account): User
    {
        try {
            return DB::transaction(function () use ($account): User {
                $user = User::query()->create([
                    'name' => $account->name,
                    'email' => $account->email,
                    'avatar_url' => $account->avatarUrl,
                ]);

                $user->signInIdentities()->create([
                    'provider' => IdentityProvider::Google,
                    'provider_user_id' => $account->id,
                    'login' => $account->email,
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return SignInIdentity::findFor(IdentityProvider::Google, $account->id)->user ?? throw $exception;
        }
    }
}
