<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Mcp\StarCaller;
use App\Models\ActivityEntry;
use App\Models\Connection;

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
     * @param  Connection|null  $connection  The Connection the name belongs to, or null when none of the Star's does.
     * @param  string|null  $downstreamName  The name on the Connection's server, or null when there is none.
     */
    public function handle(
        StarCaller $caller,
        ActivityKind $kind,
        string $exposedName,
        ?Connection $connection,
        ?string $downstreamName,
        ActivityStatus $status,
        int $startedAt,
    ): ActivityEntry {
        return ActivityEntry::query()->create([
            'user_id' => $caller->star->user_id,
            'star_id' => $caller->star->id,
            'connection_id' => $connection?->id,
            'kind' => $kind,
            'exposed_name' => mb_substr($exposedName, 0, self::EXPOSED_NAME_LENGTH),
            'downstream_name' => $downstreamName,
            'status' => $status,
            'via' => $caller->via,
            'client_name' => $caller->clientName,
            'duration_ms' => (int) round(max(0, hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }
}
