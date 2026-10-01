<?php

declare(strict_types=1);

use App\Actions\CreateStar;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use App\Stars\StarTool;
use App\Stars\StarToolset;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Stars')] class extends Component
{
    public string $name = '';

    public string $description = '';

    /**
     * The ids of the Connections the new Star includes.
     *
     * @var list<string>
     */
    public array $connectionIds = [];

    #[Computed]
    public function user(): User
    {
        return Auth::user() ?? throw new AuthenticationException;
    }

    /**
     * @return Collection<int, Star>
     */
    #[Computed]
    public function stars(): Collection
    {
        return $this->user->stars()
            ->with(['connections' => fn (Relation $query): Relation => $query->orderBy('name')->orderBy('connections.id')])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * How many of each Star's tools are on, and how many it has, by Star id.
     *
     * @return array<int, array{enabled: int, total: int}>
     */
    #[Computed]
    public function toolCounts(): array
    {
        $toolset = resolve(StarToolset::class);

        return $this->stars->mapWithKeys(function (Star $star) use ($toolset): array {
            $tools = $toolset->tools($star);

            return [$star->id => [
                'enabled' => count(array_filter($tools, fn (StarTool $tool): bool => $tool->enabled)),
                'total' => count($tools),
            ]];
        })->all();
    }

    /**
     * The Connections a new Star can include.
     *
     * @return Collection<int, Connection>
     */
    #[Computed]
    public function connections(): Collection
    {
        return $this->user->connections()->withCount('tools')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * What to tell the user when they can't create another Star, or null when they can.
     */
    #[Computed]
    public function limitMessage(): ?string
    {
        return $this->user->hasReachedStarLimit() ? CreateStar::limitMessage() : null;
    }

    public function create(CreateStar $createStar): void
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);

        $this->validate(
            [
                'name' => ['required', 'string', 'max:100'],
                'description' => ['nullable', 'string', 'max:500'],
                'connectionIds' => ['array'],
                'connectionIds.*' => ['integer', Rule::exists('connections', 'id')->where('user_id', $this->user->id)],
            ],
            ['connectionIds.*.exists' => __('Choose only your own Connections.')],
            ['connectionIds' => __('connections')],
        );

        $star = $createStar->handle(
            $this->user,
            ['name' => $this->name, 'description' => $this->description === '' ? null : $this->description],
            array_map(intval(...), $this->connectionIds),
        );

        session()->flash('toast', ['variant' => 'success', 'text' => __('Created :name.', ['name' => $star->name])]);

        $this->redirectRoute('stars.show', ['star' => $star], navigate: true);
    }
};
