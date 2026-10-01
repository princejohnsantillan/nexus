<?php

declare(strict_types=1);

use App\Models\Connection;
use App\Models\ConnectionTool;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Connection tools')] class extends Component
{
    public Connection $connection;

    /**
     * @return Collection<int, ConnectionTool>
     */
    #[Computed]
    public function tools(): Collection
    {
        return $this->connection->tools()->orderBy('name')->get();
    }
};
