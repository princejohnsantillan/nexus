<?php

declare(strict_types=1);

use App\Models\Connection;
use App\Models\ConnectionPrompt;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Connection prompts')] class extends Component
{
    public Connection $connection;

    /**
     * @return Collection<int, ConnectionPrompt>
     */
    #[Computed]
    public function prompts(): Collection
    {
        return $this->connection->prompts()->orderBy('name')->get();
    }
};
