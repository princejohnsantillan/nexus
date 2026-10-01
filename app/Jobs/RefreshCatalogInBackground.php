<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\RefreshCatalog;
use App\Downstream\DownstreamClient;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Refreshes a Connection's catalog on the queue with RefreshCatalog: every
 * day, when a Star lists tools from a stale catalog, and when a call fails
 * because the server doesn't know the tool.
 *
 * It is unique per Connection: while one is queued or running, no other is
 * queued. Its lock goes when it has run or failed, including when a worker
 * dies during it, since the queue then hands it out again and it fails as
 * attempted too many times. The lock lasts at most 30 days, longer than any
 * job waits in the queue (SQS keeps a message 14 days at most), so only a
 * lock whose job was lost from the queue ever expires. It carries the
 * Connection's id rather than the model, so a Connection deleted meanwhile
 * is simply skipped. It is tried once: the next daily or stale refresh
 * tries again.
 *
 * A server that can't be listed is recorded on the Connection, as
 * RefreshCatalog records it, and nothing is logged. A refresh the worker
 * stops (it took too long, or a worker died during it) is recorded there
 * too by failed(), and kept out of the log (see isGivenUp()).
 *
 * Laravel Cloud's Flex queue ends a job after 90 seconds without warning,
 * and the database queue hands a job to another worker after its 90-second
 * `retry_after`, so the worker stops this one well before either, at 60.
 * The worker can only stop it once the request in flight returns, so each
 * request to the server waits at most 20 seconds rather than the usual
 * call timeout (the handshake's 10-second connect timeout is unchanged):
 * even a request sent just before the 60th second ends by the 80th.
 */
#[Tries(1)]
#[Timeout(self::TIMEOUT)]
#[FailOnTimeout]
#[UniqueFor(self::UNIQUE_FOR)]
final class RefreshCatalogInBackground implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * How long the refresh may run, in seconds.
     */
    public const int TIMEOUT = 60;

    /**
     * How long each request to the server may take, in seconds.
     */
    public const int CALL_TIMEOUT = 20;

    /**
     * How long the lock that keeps it unique lasts at most, in seconds: 30 days.
     */
    public const int UNIQUE_FOR = 30 * 24 * 60 * 60;

    /**
     * What a stopped refresh may only overwrite unchanged: the Connection's
     * server and sign-in (its credentials too, except an OAuth Connection's,
     * which are renewed as they are used) and the outcome of its last
     * refresh. A new name or note doesn't count.
     *
     * @var list<string>
     */
    private const array STATE_COLUMNS = ['url', 'auth_type', 'settings', 'secrets', 'status', 'last_error', 'catalog_refreshed_at'];

    public function __construct(public readonly int $connectionId) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    /**
     * Whether the exception is the worker giving up on one of these
     * refreshes, because it took too long or a worker died during it.
     * failed() records that on the Connection, so it isn't reported.
     */
    public static function isGivenUp(Throwable $exception): bool
    {
        return $exception instanceof MaxAttemptsExceededException && $exception->job?->resolveName() === self::class;
    }

    /**
     * Refresh the catalog, remembering the Connection as it was when the
     * refresh started, for failed().
     */
    public function handle(DownstreamClient $downstream): void
    {
        $connection = Connection::query()->find($this->connectionId);

        if (! $connection instanceof Connection) {
            return;
        }

        Cache::put($this->startedFromKey(), $this->stateOf($connection), now()->addWeek());

        resolve(RefreshCatalog::class, ['downstream' => $downstream->withCallTimeout(self::CALL_TIMEOUT)])->handle($connection);

        Cache::forget($this->startedFromKey());
    }

    /**
     * Record on the Connection that the refresh was stopped, unless
     * something newer says how the Connection is: its server, sign-in or
     * last refresh changed since this refresh started (or, for one that
     * never started, it was refreshed since this one was queued).
     */
    public function failed(?Throwable $exception): void
    {
        $startedFrom = Cache::pull($this->startedFromKey());
        $queuedAt = $this->job?->payload()['createdAt'] ?? null;

        DB::transaction(function () use ($exception, $startedFrom, $queuedAt): void {
            $connection = Connection::query()->whereKey($this->connectionId)->lockForUpdate()->first();

            if (! $connection instanceof Connection || ! $this->isAsItWas($connection, $startedFrom, $queuedAt)) {
                return;
            }

            $connection->forceFill([
                'status' => ConnectionStatus::Error,
                'last_error' => $exception instanceof TimeoutExceededException
                    ? __('Refreshing the tools in the background took longer than :seconds seconds, so Nexus stopped.', ['seconds' => self::TIMEOUT])
                    : __('Nexus could not refresh the tools in the background.'),
            ])->save();
        });
    }

    /**
     * @param  mixed  $startedFrom  The Connection's state when the refresh started, from stateOf(), if it started.
     * @param  mixed  $queuedAt  When the refresh was queued, a Unix timestamp, if known.
     */
    private function isAsItWas(Connection $connection, mixed $startedFrom, mixed $queuedAt): bool
    {
        if (is_array($startedFrom)) {
            return $this->stateOf($connection) === $startedFrom;
        }

        return ! is_int($queuedAt) || $connection->catalog_refreshed_at === null || $connection->catalog_refreshed_at->getTimestamp() <= $queuedAt;
    }

    /**
     * The Connection's state columns as stored, each hashed, so the cache
     * never holds its ciphertext.
     *
     * @return list<string|null>
     */
    private function stateOf(Connection $connection): array
    {
        $usesOAuth = $connection->getRawOriginal('auth_type') === ConnectionAuthType::OAuth->value;

        return array_map(function (string $column) use ($connection, $usesOAuth): ?string {
            $value = $usesOAuth && $column === 'secrets' ? null : $connection->getRawOriginal($column);

            return is_scalar($value) ? hash('sha256', (string) $value) : null;
        }, self::STATE_COLUMNS);
    }

    private function startedFromKey(): string
    {
        return "connections.{$this->connectionId}.background-refresh";
    }
}
