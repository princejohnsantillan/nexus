<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\StarToolSwitch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class SwitchStarTools
{
    /**
     * How many switches one insert writes.
     */
    private const int CHUNK_SIZE = 200;

    /**
     * Switch tools of one of the Star's Connections on or off as the user's
     * own choice, or, with null, take that choice away so they follow the
     * Star's new-tool policy again.
     *
     * Given names, only those tools change, and only the ones in the
     * Connection's catalog get a switch. Without names the whole Connection
     * changes: every tool in its catalog gets the switch, and switches left
     * over for tools the server no longer lists are removed, so a tool that
     * comes back later follows the policy.
     *
     * The Star's and the Connection's rows are held while the switches are
     * written, so neither can be deleted, nor the Connection taken out of
     * the Star, halfway through.
     *
     * @param  list<string>|null  $toolNames
     *
     * @throws ModelNotFoundException<Star|Connection> when the Star no longer exists or no longer includes the Connection
     */
    public function handle(Star $star, Connection $connection, ?bool $enabled, ?array $toolNames = null): void
    {
        DB::transaction(function () use ($star, $connection, $enabled, $toolNames): void {
            Star::query()->whereKey($star->id)->lockForUpdate()->firstOrFail();
            $star->connections()->whereKey($connection->id)->lockForUpdate()->firstOrFail();

            $switches = $star->toolSwitches()->where('connection_id', $connection->id);

            if ($toolNames === null) {
                $switches->delete();
            } elseif ($enabled === null) {
                $switches->whereIn('tool_name', $toolNames)->delete();
            }

            if ($enabled === null) {
                return;
            }

            $tools = $connection->tools()->when($toolNames !== null, fn (Builder $query): Builder => $query->whereIn('name', $toolNames ?? []))->get(['name']);
            $now = now();

            foreach ($tools->chunk(self::CHUNK_SIZE) as $chunk) {
                StarToolSwitch::query()->upsert(
                    $chunk->map(fn (ConnectionTool $tool): array => [
                        'star_id' => $star->id,
                        'connection_id' => $connection->id,
                        'tool_name' => $tool->name,
                        'enabled' => $enabled,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->values()->all(),
                    ['star_id', 'connection_id', 'tool_name'],
                    ['enabled', 'updated_at'],
                );
            }
        });
    }
}
