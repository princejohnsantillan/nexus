<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\IdentityProvider;
use App\Models\SignInIdentity;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveSignInIdentity
{
    /**
     * How long one removal may hold a user's lock, in seconds; it only
     * counts and deletes, so it needs far less.
     */
    private const int LOCK_SECONDS = 10;

    /**
     * How long another removal for the same user waits for the lock, in seconds.
     */
    private const int WAIT_SECONDS = 5;

    /**
     * Remove one of a user's sign-in identities, of any provider, as long as
     * they keep another one to sign in with. Removals for one user hold a
     * cache lock while they count and delete, so removing the last two at
     * once can't leave the account with no way in.
     *
     * Removing a GitHub identity also clears the user's old GitHub columns,
     * which GitHub sign-in would otherwise use to find the user and give
     * them the identity again. Once removed, signing in with that account
     * creates a new Nexus account, like any identity nobody has.
     *
     * @throws ValidationException (under `identity`) when it is the user's only sign-in identity, or another removal of theirs holds the lock too long
     */
    public function handle(SignInIdentity $identity): void
    {
        $lock = Cache::lock("users.{$identity->user_id}.sign-in-identities", self::LOCK_SECONDS);

        try {
            $lock->block(self::WAIT_SECONDS);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['identity' => __('Another sign-in method is being removed from your account. Try again in a moment.')]);
        }

        try {
            $othersRemain = SignInIdentity::query()
                ->where('user_id', $identity->user_id)
                ->whereKeyNot($identity->id)
                ->exists();

            if (! $othersRemain) {
                throw ValidationException::withMessages(['identity' => __("This is the only way you sign in, so it can't be removed. Add another sign-in method first.")]);
            }

            DB::transaction(function () use ($identity): void {
                $identity->delete();

                if ($identity->provider === IdentityProvider::GitHub) {
                    User::query()
                        ->whereKey($identity->user_id)
                        ->where('github_id', $identity->provider_user_id)
                        ->update(['github_id' => null, 'github_login' => null]);
                }
            });
        } finally {
            $lock->release();
        }
    }
}
