<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RefreshCatalogInBackground;
use App\Models\Connection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nexus:refresh-catalogs')]
#[Description('Queue a background refresh of every Connection\'s catalog (the scheduler runs this daily)')]
class RefreshCatalogsCommand extends Command
{
    /**
     * Queue one refresh per Connection; a Connection whose refresh is
     * already queued or running isn't queued again.
     */
    public function handle(): int
    {
        $queued = 0;

        foreach (Connection::query()->select('id')->lazyById() as $connection) {
            RefreshCatalogInBackground::dispatch($connection->id);
            $queued++;
        }

        $this->components->info(trans_choice('Queued a catalog refresh for :count Connection.|Queued a catalog refresh for each of :count Connections.', $queued));

        return self::SUCCESS;
    }
}
