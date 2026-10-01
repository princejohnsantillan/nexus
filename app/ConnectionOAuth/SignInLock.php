<?php

declare(strict_types=1);

namespace App\ConnectionOAuth;

use App\Models\Connection;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

/**
 * One change at a time to a Connection's sign-in: renewing its OAuth
 * tokens, storing a new sign-in or a client its server registered, and
 * changing its server or how Nexus signs in to it.
 *
 * Each change holds this cache lock for the Connection and works on the
 * Connection as re-read once it has the lock, so it neither acts on
 * credentials another change has replaced nor writes stale ones back.
 */
final readonly class SignInLock
{
    /**
     * How long one change may hold the lock, in seconds: well over a token
     * request's timeout.
     */
    private const int SECONDS = 30;

    /**
     * How long another change waits for the lock, in seconds.
     */
    private const int WAIT_SECONDS = 15;

    /**
     * Make a change holding the Connection's lock, with the Connection
     * re-read from the database first.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult
     *
     * @throws LockTimeoutException when another change holds the lock too long
     * @throws ModelNotFoundException when the Connection has been deleted
     */
    public function hold(Connection $connection, Closure $change): mixed
    {
        return Cache::lock("connections.{$connection->id}.oauth-tokens", self::SECONDS)->block(self::WAIT_SECONDS, function () use ($connection, $change): mixed {
            $connection->refresh();

            return $change();
        });
    }
}
