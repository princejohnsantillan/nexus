<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\RefreshCatalog;
use App\Downstream\DownstreamClient;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * Refreshes a Connection's catalog on the queue with RefreshCatalog: every
 * day, when a Star lists tools from a stale catalog, and when a call fails
 * because the server doesn't know the tool.
 *
 * It is unique per Connection, so a refresh that is already queued or
 * running isn't queued again. It carries the Connection's id rather than
 * the model, so a Connection deleted meanwhile is simply skipped. A server
 * that can't be listed is recorded on the Connection, as RefreshCatalog
 * records it, and nothing is logged; a refresh the worker stops, such as
 * one that takes too long, is recorded there too by failed(). It is tried
 * once: the next daily or stale refresh tries again.
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
#[UniqueFor(3600)]
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

    public function __construct(public readonly int $connectionId) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(DownstreamClient $downstream): void
    {
        $connection = Connection::query()->find($this->connectionId);

        if (! $connection instanceof Connection) {
            return;
        }

        resolve(RefreshCatalog::class, ['downstream' => $downstream->withCallTimeout(self::CALL_TIMEOUT)])->handle($connection);
    }

    /**
     * Record on the Connection that the refresh was stopped, unless the
     * Connection was saved since the refresh could have started: then
     * something newer, such as a new sign-in, says how it is.
     */
    public function failed(?Throwable $exception): void
    {
        Connection::query()
            ->whereKey($this->connectionId)
            ->where('updated_at', '<=', now()->subSeconds(self::TIMEOUT))
            ->update([
                'status' => ConnectionStatus::Error,
                'last_error' => $exception instanceof TimeoutExceededException
                    ? __('Refreshing the tools in the background took longer than :seconds seconds, so Nexus stopped.', ['seconds' => self::TIMEOUT])
                    : __('Nexus could not refresh the tools in the background.'),
            ]);
    }
}
