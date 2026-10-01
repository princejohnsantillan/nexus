<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Connection;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class SaveNewConnection
{
    /**
     * How long one save may hold a user's lock, in seconds; it only counts
     * and inserts, so it needs far less.
     */
    private const int LOCK_SECONDS = 10;

    /**
     * How long another save for the same user waits for the lock, in seconds.
     */
    private const int WAIT_SECONDS = 5;

    /**
     * Save a new Connection for the user, unless they already have as many as
     * `nexus.limits.connections_per_user` allows. Saves for one user hold a
     * cache lock while they count and insert, so two at once can't both pass
     * the check. Do slow work, such as loading tools, after this returns.
     *
     * @throws ValidationException (under `limit`) when the user is at the limit, or another save of theirs holds the lock too long
     */
    public function handle(User $user, Connection $connection): void
    {
        try {
            Cache::lock("users.{$user->id}.new-connection", self::LOCK_SECONDS)->block(self::WAIT_SECONDS, function () use ($user, $connection): void {
                if ($user->hasReachedConnectionLimit()) {
                    throw ValidationException::withMessages(['limit' => self::limitMessage()]);
                }

                $connection->user()->associate($user)->save();
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['limit' => __('Another Connection is being added to your account. Try again in a moment.')]);
        }
    }

    /**
     * What a user who has reached the Connections limit is told.
     */
    public static function limitMessage(): string
    {
        return __('You have :limit Connections, the most an account can have. Delete one to add another.', [
            'limit' => config()->integer('nexus.limits.connections_per_user'),
        ]);
    }
}
