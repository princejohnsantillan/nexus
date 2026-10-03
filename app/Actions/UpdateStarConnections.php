<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Connection;
use App\Models\Star;
use App\Stars\StarListCache;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class UpdateStarConnections
{
    public function __construct(private readonly StarListCache $starLists) {}

    /**
     * Make the Star include exactly those of the given Connections that
     * belong to its user; anyone else's are ignored. A Connection taken out
     * loses the switches the user set for its tools and prompts in this
     * Star, so adding it back starts again from the new-tool policy, with
     * every prompt on.
     *
     * The Star's row and the chosen Connections' rows are held while the
     * change is written, so neither can be deleted halfway through. Once it
     * is committed, the Star's lists are worked out afresh (StarListCache).
     *
     * @param  list<int>  $connectionIds
     *
     * @throws ModelNotFoundException<Star> when the Star no longer exists
     */
    public function handle(Star $star, array $connectionIds): void
    {
        DB::transaction(function () use ($star, $connectionIds): void {
            Star::query()->whereKey($star->id)->lockForUpdate()->firstOrFail();

            $this->starLists->forget($star);

            $ids = Connection::query()->where('user_id', $star->user_id)->whereKey($connectionIds)->lockForUpdate()->pluck('id')->all();

            /** @var array{attached: list<int>, detached: list<int>, updated: list<int>} $changes */
            $changes = $star->connections()->sync($ids);

            if ($changes['detached'] !== []) {
                $star->toolSwitches()->whereIn('connection_id', $changes['detached'])->delete();
                $star->promptSwitches()->whereIn('connection_id', $changes['detached'])->delete();
            }
        });
    }

    /**
     * Add one Connection to the Star, keeping the ones it has, through
     * handle() and so by its rules: its tools start from the new-tool
     * policy, and only a Connection of the Star's own user is added. The
     * Star's row is held while its Connections are read, so a change made
     * meanwhile (on the Star's page, say) is kept, not undone.
     *
     * Returns whether the Star includes the Connection now and didn't
     * before: false, changing nothing, when it already did, the Connection
     * isn't its user's, or it was deleted meanwhile.
     *
     * @throws ModelNotFoundException<Star> when the Star no longer exists
     */
    public function add(Star $star, Connection $connection): bool
    {
        if ($connection->user_id !== $star->user_id) {
            return false;
        }

        return DB::transaction(function () use ($star, $connection): bool {
            Star::query()->whereKey($star->id)->lockForUpdate()->firstOrFail();

            if ($star->connections()->whereKey($connection->id)->exists()) {
                return false;
            }

            $ids = $star->connections()->get(['connections.id'])->map(fn (Connection $included): int => $included->id)->all();

            $this->handle($star, [...$ids, $connection->id]);

            return $star->connections()->whereKey($connection->id)->exists();
        });
    }
}
