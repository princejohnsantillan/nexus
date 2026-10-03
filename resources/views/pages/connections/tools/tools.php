<?php

declare(strict_types=1);

use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Stars\ToolDetails;
use App\Stars\ToolDetailsReader;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Connection tools')] class extends Component
{
    public Connection $connection;

    /**
     * The catalog id of the tool whose details flyout is open, or null.
     */
    public ?int $detailsToolId = null;

    /**
     * @return Collection<int, ConnectionTool>
     */
    #[Computed]
    public function tools(): Collection
    {
        return $this->connection->tools()->orderBy('name')->get();
    }

    /**
     * What the details flyout shows for its tool, or null when none is open
     * or the Connection no longer has it.
     */
    #[Computed]
    public function toolDetails(): ?ToolDetails
    {
        $tool = $this->detailsToolId === null ? null : $this->tools->find($this->detailsToolId);

        return $tool === null ? null : resolve(ToolDetailsReader::class)->read($tool->setRelation('connection', $this->connection));
    }

    /**
     * Open the details flyout for one of the Connection's tools.
     */
    public function showToolDetails(int $toolId): void
    {
        $this->tools->find($toolId) ?? abort(404);

        $this->detailsToolId = $toolId;

        unset($this->toolDetails);

        $this->dispatch('modal-show', name: 'tool-details', scope: $this->getId());
    }
};
