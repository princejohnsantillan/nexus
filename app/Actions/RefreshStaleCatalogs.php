<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\RefreshCatalogInBackground;
use App\Models\Star;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the catalogs a Star serves from going stale: once the response has
 * been sent, so serving a list never waits for it, it queues a background
 * refresh of each of the Star's Connections whose catalog is older than
 * `nexus.catalogs.stale_after_minutes`, or never loaded.
 *
 * Each Connection is queued this way at most once in that time, so a
 * server that keeps failing, whose catalog stays stale, isn't asked again
 * on every list; the daily refresh and "Refresh tools" still try it.
 */
final readonly class RefreshStaleCatalogs
{
    public function handle(Star $star): void
    {
        defer(function () use ($star): void {
            $staleAfterMinutes = config()->integer('nexus.catalogs.stale_after_minutes');

            $stale = $star->connections()
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('catalog_refreshed_at')
                    ->orWhere('catalog_refreshed_at', '<', now()->subMinutes($staleAfterMinutes)))
                ->get(['connections.id']);

            foreach ($stale as $connection) {
                if (Cache::add("connections.{$connection->id}.stale-catalog-refresh", true, now()->addMinutes($staleAfterMinutes))) {
                    RefreshCatalogInBackground::dispatch($connection->id);
                }
            }
        });
    }
}
