<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Star;
use App\Models\StarToken;
use App\Stars\NewStarToken;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class CreateStarToken
{
    /**
     * How long one creation may hold a Star's lock, in seconds; it only
     * counts and inserts, so it needs far less.
     */
    private const int LOCK_SECONDS = 10;

    /**
     * How long another creation for the same Star waits for the lock, in seconds.
     */
    private const int WAIT_SECONDS = 5;

    /**
     * Create a named token for the Star, unless it already has as many as
     * `nexus.limits.tokens_per_star` allows. Only the token's hash is
     * stored; the plain token comes back once, to show the user. Creations
     * for one Star hold a cache lock while they count and insert, so two at
     * once can't both pass the check.
     *
     * @throws ValidationException (under `limit`) when the Star is at the limit, or another creation for it holds the lock too long
     */
    public function handle(Star $star, string $name): NewStarToken
    {
        $lock = Cache::lock("stars.{$star->id}.new-token", self::LOCK_SECONDS);

        try {
            $lock->block(self::WAIT_SECONDS);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['limit' => __('Another token is being created for this Star. Try again in a moment.')]);
        }

        try {
            if ($star->hasReachedTokenLimit()) {
                throw ValidationException::withMessages(['limit' => self::limitMessage()]);
            }

            $plainTextToken = StarToken::generate();

            $token = $star->tokens()->make(['name' => $name]);
            $token->setPlainToken($plainTextToken);
            $token->save();

            return new NewStarToken($token, $plainTextToken);
        } finally {
            $lock->release();
        }
    }

    /**
     * What a user whose Star has reached the tokens limit is told.
     */
    public static function limitMessage(): string
    {
        $limit = config()->integer('nexus.limits.tokens_per_star');

        return trans_choice(
            'This Star has :limit token, the most a Star can have. Revoke it to create another.|This Star has :limit tokens, the most a Star can have. Revoke one to create another.',
            $limit,
            ['limit' => $limit],
        );
    }
}
