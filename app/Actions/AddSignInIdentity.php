<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\IdentityProvider;
use App\Exceptions\IdentityBelongsToAnotherUser;
use App\Models\SignInIdentity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AddSignInIdentity
{
    /**
     * Add a sign-in identity to a signed-in user, from Settings, after the
     * provider vouched for it. Nexus never adds one by matching an email
     * address.
     *
     * An identity the user already has keeps its place and gets the login the
     * provider sent; it comes back with `wasRecentlyCreated` false. One that
     * another user has is refused. Two additions of the same identity at once
     * both get to the insert, and the second breaks the unique index: the
     * insert runs in its own transaction (a savepoint inside another), so on
     * Postgres the failure doesn't spoil the rest, and the identity the other
     * one added is judged like any existing one.
     *
     * @throws IdentityBelongsToAnotherUser when another user signs in with the identity
     */
    public function handle(User $user, IdentityProvider $provider, string $providerUserId, string $login): SignInIdentity
    {
        $existing = SignInIdentity::findFor($provider, $providerUserId);

        if ($existing instanceof SignInIdentity) {
            return $this->claim($user, $existing, $login);
        }

        try {
            return DB::transaction(fn (): SignInIdentity => $user->signInIdentities()->create([
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'login' => $login,
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->claim($user, SignInIdentity::findFor($provider, $providerUserId) ?? throw $exception, $login);
        }
    }

    /**
     * @throws IdentityBelongsToAnotherUser
     */
    private function claim(User $user, SignInIdentity $identity, string $login): SignInIdentity
    {
        if ($identity->user_id !== $user->id) {
            throw new IdentityBelongsToAnotherUser($identity->provider);
        }

        $identity->update(['login' => $login]);

        return $identity;
    }
}
