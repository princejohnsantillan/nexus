<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\Star;
use App\Models\StarPromptSwitch;
use App\Stars\StarListCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class SwitchStarPrompts
{
    /**
     * How many switches one insert writes.
     */
    private const int CHUNK_SIZE = 200;

    public function __construct(private readonly StarListCache $starLists) {}

    /**
     * Switch prompts of one of the Star's Connections on or off as the
     * user's own choice, or, with null, take that choice away so they are
     * on by default again.
     *
     * Given names, only those prompts change, and only the ones in the
     * Connection's catalog get a switch. Without names the whole Connection
     * changes: every prompt in its catalog gets the switch, and switches
     * left over for prompts the server no longer lists are removed, so a
     * prompt that comes back later is on by default.
     *
     * The Star's and the Connection's rows are held while the switches are
     * written, so neither can be deleted, nor the Connection taken out of
     * the Star, halfway through. Once they are committed, the Star's lists
     * are worked out afresh (StarListCache).
     *
     * @param  list<string>|null  $promptNames
     *
     * @throws ModelNotFoundException<Star|Connection> when the Star no longer exists or no longer includes the Connection
     */
    public function handle(Star $star, Connection $connection, ?bool $enabled, ?array $promptNames = null): void
    {
        DB::transaction(function () use ($star, $connection, $enabled, $promptNames): void {
            Star::query()->whereKey($star->id)->lockForUpdate()->firstOrFail();
            $star->connections()->whereKey($connection->id)->lockForUpdate()->firstOrFail();

            $this->starLists->forget($star);

            $switches = $star->promptSwitches()->where('connection_id', $connection->id);

            if ($promptNames === null) {
                $switches->delete();
            } elseif ($enabled === null) {
                $switches->whereIn('prompt_name', $promptNames)->delete();
            }

            if ($enabled === null) {
                return;
            }

            $prompts = $connection->prompts()->when($promptNames !== null, fn (Builder $query): Builder => $query->whereIn('name', $promptNames ?? []))->get(['name']);
            $now = now();

            foreach ($prompts->chunk(self::CHUNK_SIZE) as $chunk) {
                StarPromptSwitch::query()->upsert(
                    $chunk->map(fn (ConnectionPrompt $prompt): array => [
                        'star_id' => $star->id,
                        'connection_id' => $connection->id,
                        'prompt_name' => $prompt->name,
                        'enabled' => $enabled,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->values()->all(),
                    ['star_id', 'connection_id', 'prompt_name'],
                    ['enabled', 'updated_at'],
                );
            }
        });
    }
}
