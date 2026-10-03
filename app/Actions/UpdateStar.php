<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Star;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class UpdateStar
{
    public function __construct(private readonly UpdateStarConnections $updateStarConnections) {}

    /**
     * Save a Star's name and description and, unless null, the Connections
     * it includes, all or nothing. The Connections change through
     * UpdateStarConnections, and so by its rules: only the Star's user's
     * are included, one taken out loses its switches here, and the Star's
     * lists are worked out afresh. Renaming keeps the Star's URL.
     *
     * @param  list<int>|null  $connectionIds  The Connections to include, or null to leave them as they are.
     *
     * @throws ModelNotFoundException<Star> when the Star no longer exists
     */
    public function handle(Star $star, string $name, ?string $description, ?array $connectionIds = null): void
    {
        DB::transaction(function () use ($star, $name, $description, $connectionIds): void {
            if ($connectionIds !== null) {
                $this->updateStarConnections->handle($star, $connectionIds);
            }

            $star->update(['name' => $name, 'description' => $description]);
        });
    }
}
