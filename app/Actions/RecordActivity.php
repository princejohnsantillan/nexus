<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Mcp\StarCaller;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use Illuminate\Database\QueryException;

class RecordActivity
{
    /**
     * The longest exposed name kept: a handle, the separator and the longest
     * tool name. A longer one came from the client and can't match a tool.
     */
    private const int EXPOSED_NAME_LENGTH = 160;

    /**
     * Record one call through a Star: what was called, how it ended, how long
     * it took since it started (an `hrtime(true)` reading) and how the client
     * authenticated. It takes no arguments, results or messages, so none of
     * them can be stored.
     *
     * A call can outlive what it refers to: the Star or the Connection may be
     * deleted while the server answers. The entry then keeps null in their
     * place, as it would had they been deleted afterwards; when the user is
     * gone too, their activity went with them, so nothing is recorded.
     *
     * @param  string|null  $exposedName  The name the client called, or null when it sent none.
     * @param  Connection|null  $connection  The Connection the name belongs to, or null when none of the Star's does.
     * @param  string|null  $downstreamName  The name on the Connection's server, or null when there is none.
     * @return ActivityEntry|null The entry, or null when the user no longer exists.
     */
    public function handle(
        StarCaller $caller,
        ActivityKind $kind,
        ?string $exposedName,
        ?Connection $connection,
        ?string $downstreamName,
        ActivityStatus $status,
        int $startedAt,
    ): ?ActivityEntry {
        $entry = new ActivityEntry([
            'user_id' => $caller->star->user_id,
            'star_id' => $caller->star->id,
            'connection_id' => $connection?->id,
            'kind' => $kind,
            'exposed_name' => $exposedName === null ? null : mb_substr($exposedName, 0, self::EXPOSED_NAME_LENGTH),
            'downstream_name' => $downstreamName,
            'status' => $status,
            'via' => $caller->via,
            'client_name' => $caller->clientName,
            'duration_ms' => (int) round(max(0, hrtime(true) - $startedAt) / 1_000_000),
        ]);

        try {
            $entry->save();
        } catch (QueryException $exception) {
            if (! $this->violatesIntegrity($exception)) {
                throw $exception;
            }

            if (! User::query()->whereKey($entry->user_id)->exists()) {
                return null;
            }

            $entry->star_id = Star::query()->whereKey($entry->star_id)->exists() ? $entry->star_id : null;
            $entry->connection_id = Connection::query()->whereKey($entry->connection_id)->exists() ? $entry->connection_id : null;
            $entry->save();
        }

        return $entry;
    }

    /**
     * Whether the insert broke a constraint (SQLSTATE class 23), as it does
     * when a record it refers to was deleted meanwhile.
     */
    private function violatesIntegrity(QueryException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? null;

        return is_string($state) && str_starts_with($state, '23');
    }
}
