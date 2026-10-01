<?php

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Mcp\Downstream\ConnectionCatalog;
use App\Models\Connection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('nexus:refresh-catalogs')]
#[Description('Re-read the tool list of every active connection')]
class RefreshConnectionCatalogs extends Command
{
    public function handle(ConnectionCatalog $catalog): int
    {
        Connection::query()
            ->where('status', ConnectionStatus::Active)
            ->lazyById()
            ->each(function (Connection $connection) use ($catalog): void {
                try {
                    $count = $catalog->refresh($connection);
                    $this->components->info("{$connection->id} {$connection->handle}: {$count} tools");
                } catch (Throwable $exception) {
                    $this->components->warn("{$connection->id} {$connection->handle}: {$exception->getMessage()}");
                }
            });

        return self::SUCCESS;
    }
}
