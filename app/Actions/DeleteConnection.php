<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Connection;
use Illuminate\Support\Facades\DB;

class DeleteConnection
{
    /**
     * Delete the Connection, with its catalog, and take it out of every
     * Star, whose lists are then worked out afresh.
     *
     * Its row is held while it is deleted, as adding Connections to a Star
     * holds them (UpdateStarConnections, CreateStar): a Star it was being
     * added to meanwhile has it by the time the Stars that include it are
     * found, so that Star's lists are forgotten too, or else finds it gone
     * and doesn't add it.
     */
    public function handle(Connection $connection): void
    {
        DB::transaction(function () use ($connection): void {
            Connection::query()->whereKey($connection->id)->lockForUpdate()->first(['id']);

            $connection->delete();
        });
    }
}
