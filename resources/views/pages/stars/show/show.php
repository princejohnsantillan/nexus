<?php

declare(strict_types=1);

use App\Actions\DeleteStar;
use App\Actions\UpdateStarConnections;
use App\Models\Connection;
use App\Models\Star;
use App\Stars\ClientSetup;
use App\Stars\StarStats;
use App\Stars\StarStatsCounter;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Star')] class extends Component
{
    public Star $star;

    public string $name = '';

    public string $description = '';

    /**
     * The ids of the Connections the Star includes.
     *
     * @var list<string>
     */
    public array $connectionIds = [];

    public function mount(): void
    {
        $this->name = $this->star->name;
        $this->description = $this->star->description ?? '';
        $this->resetConnections();
    }

    /**
     * The Connections the Star can include: every one of its user's.
     *
     * @return Collection<int, Connection>
     */
    #[Computed]
    public function connections(): Collection
    {
        return $this->star->user->connections()->withCount('tools')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Whether the Star is working: its tools that are on, and its calls.
     */
    #[Computed]
    public function stats(): StarStats
    {
        return resolve(StarStatsCounter::class)->for($this->star);
    }

    /**
     * Copy-paste setup for each client, for the Star's access mode.
     *
     * @return list<array{client: string, file: string|null, instruction: string|null, snippet: string, login: array{instruction: string, snippet: string|null}|null}>
     */
    #[Computed]
    public function clientSetup(): array
    {
        return resolve(ClientSetup::class)->for($this->star);
    }

    /**
     * The environment variable the setup reads the Star's token from.
     */
    #[Computed]
    public function tokenVariable(): string
    {
        return ClientSetup::tokenVariable($this->star);
    }

    public function saveConnections(UpdateStarConnections $updateStarConnections): void
    {
        $this->validate(
            [
                'connectionIds' => ['array'],
                'connectionIds.*' => ['integer', Rule::exists('connections', 'id')->where('user_id', $this->star->user_id)],
            ],
            ['connectionIds.*.exists' => __('Choose only your own Connections.')],
        );

        $updateStarConnections->handle($this->star, array_map(intval(...), $this->connectionIds));

        unset($this->stats);
        $this->resetConnections();

        Flux::toast(variant: 'success', text: __('Saved. The Star includes :count.', [
            'count' => trans_choice(':count Connection|:count Connections', count($this->connectionIds)),
        ]));
    }

    public function saveDetails(): void
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $this->star->update([
            'name' => $this->name,
            'description' => $this->description === '' ? null : $this->description,
        ]);

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function delete(DeleteStar $deleteStar): void
    {
        $deleteStar->handle($this->star);

        session()->flash('toast', ['variant' => 'success', 'text' => __('Deleted :name.', ['name' => $this->star->name])]);

        $this->redirectRoute('stars.index', navigate: true);
    }

    /**
     * Fill the Connections picker from the Star as stored.
     */
    private function resetConnections(): void
    {
        $this->connectionIds = array_values(array_map(
            fn (Connection $connection): string => (string) $connection->id,
            $this->star->connections()->orderBy('connections.id')->get()->all(),
        ));
        $this->resetValidation(['connectionIds', 'connectionIds.*']);
    }
};
