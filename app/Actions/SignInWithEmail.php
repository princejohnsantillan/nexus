<?php

declare(strict_types=1);

namespace App\Actions;

use App\Auth\EmailCodes;
use App\Enums\IdentityProvider;
use App\Models\SignInIdentity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SignInWithEmail
{
    /**
     * The user who signs in with this email address, once a code sent to it
     * was entered: the one with its email identity, or a new account when
     * nobody has it. Only an email identity ever matches. The same address
     * on a GitHub or Google identity, or in someone's profile, doesn't, since
     * it may be someone else's: an address joins an account only from
     * Settings, while signed in.
     */
    public function handle(string $email): User
    {
        $email = EmailCodes::address($email);

        return $this->find($email) ?? $this->create($email);
    }

    private function find(string $email): ?User
    {
        return SignInIdentity::findFor(IdentityProvider::Email, $email)?->user;
    }

    /**
     * Create the account and its email identity together, named after the
     * address. Two first sign-ins with the same address at once both get
     * here; the second one's insert breaks a unique index, and it signs in to
     * the account the first one created. The insert runs in its own
     * transaction (a savepoint inside another), so on Postgres the failure
     * doesn't spoil the rest.
     */
    private function create(string $email): User
    {
        try {
            return DB::transaction(function () use ($email): User {
                $user = User::query()->create([
                    'name' => Str::limit(Str::before($email, '@'), 255, ''),
                    'email' => $email,
                ]);

                $user->signInIdentities()->create([
                    'provider' => IdentityProvider::Email,
                    'provider_user_id' => $email,
                    'login' => $email,
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->find($email) ?? throw $exception;
        }
    }
}
