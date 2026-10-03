<?php

declare(strict_types=1);

use App\Actions\DeleteStar;
use App\Actions\UpdateStar;
use App\Enums\McpClient;
use App\Models\Connection;
use App\Models\Star;
use App\Stars\ClientSetup;
use App\Stars\StarCallers;
use App\Stars\StarChanges;
use App\Stars\StarStats;
use App\Stars\StarStatsCounter;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

return new #[Title('Star')] class extends Component
{
    /**
     * The cookie that remembers the client last chosen in "Set up a client".
     */
    private const string CLIENT_COOKIE = 'nexus_setup_client';

    /**
     * How long "Check it works" listens for a call before it stops.
     */
    private const int LISTEN_SECONDS = 600;

    public Star $star;

    /**
     * The client being set up (an McpClient value).
     */
    public string $client = '';

    /**
     * What the Star had heard when the page was opened: "Check it works"
     * turns green for anything after it (`StarCallers::watermark()`).
     *
     * @var array{activity: int, tokens: array<int, int|null>, apps: array<int, int|null>}
     */
    #[Locked]
    public array $heardWhenOpened = ['activity' => 0, 'tokens' => [], 'apps' => []];

    /**
     * When "Check it works" last started listening (a Unix time).
     */
    #[Locked]
    public int $listeningSince = 0;

    /**
     * Whether "Check it works" shows its troubleshooting tips.
     */
    public bool $showTips = false;

    public string $name = '';

    public string $description = '';

    /**
     * The ids of the Connections the Star includes.
     *
     * @var list<string>
     */
    public array $connectionIds = [];

    /**
     * Whether the Connections or details differ from the Star as stored, so
     * the browser asks before leaving the page (`unsavedChangesGuard`).
     */
    #[Locked]
    public bool $hasUnsavedChanges = false;

    public function mount(): void
    {
        $this->resetFields();
        $this->client = McpClient::preferredFor($this->star->access_mode, request()->cookie(self::CLIENT_COOKIE))->value;
        $this->heardWhenOpened = resolve(StarCallers::class)->watermark($this->star);
        $this->listeningSince = now()->getTimestamp();
    }

    /**
     * Remember the chosen client in the browser, and listen afresh for its
     * first call.
     */
    public function updatedClient(): void
    {
        $this->client = $this->chosenClient->value;

        Cookie::queue(self::CLIENT_COOKIE, $this->client, 60 * 24 * 365);

        $this->listenAgain();
    }

    /**
     * Listen for a call again, for another ten minutes.
     */
    public function listenAgain(): void
    {
        $this->listeningSince = now()->getTimestamp();
    }

    public function dehydrate(): void
    {
        $this->hasUnsavedChanges = $this->star->exists && ! $this->changes->isEmpty();
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
     * What saving would change, and what each change does.
     */
    #[Computed]
    public function changes(): StarChanges
    {
        return StarChanges::of($this->star, $this->connectionIds, $this->name, $this->description);
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
     * The clients that can reach the Star in its access mode, to pick from.
     *
     * @return list<McpClient>
     */
    #[Computed]
    public function clients(): array
    {
        return McpClient::for($this->star->access_mode);
    }

    /**
     * The client being set up, or the first one when the choice can't
     * reach the Star.
     */
    #[Computed]
    public function chosenClient(): McpClient
    {
        return McpClient::preferredFor($this->star->access_mode, $this->client);
    }

    /**
     * The clients that have already reached the Star.
     *
     * @return list<McpClient>
     */
    #[Computed]
    public function clientsThatCalled(): array
    {
        return resolve(StarCallers::class)->clientsThatCalled($this->star);
    }

    /**
     * Copy-paste setup for the chosen client, for the Star's access mode.
     *
     * @return array{file: string|null, instruction: string|null, snippet: string, login: array{instruction: string, snippet: string|null}|null}
     */
    #[Computed]
    public function setup(): array
    {
        return resolve(ClientSetup::class)->for($this->star, $this->chosenClient);
    }

    /**
     * The chosen client's setup as an instruction for an agent.
     */
    #[Computed]
    public function setupPrompt(): string
    {
        return resolve(ClientSetup::class)->prompt($this->star, $this->chosenClient);
    }

    /**
     * What to do for the Star to hear from the chosen client.
     */
    #[Computed]
    public function checkHint(): string
    {
        return resolve(ClientSetup::class)->checkHint($this->star, $this->chosenClient);
    }

    /**
     * Tips for when the Star doesn't hear from the chosen client.
     *
     * @return list<string>
     */
    #[Computed]
    public function troubleshooting(): array
    {
        return resolve(ClientSetup::class)->troubleshooting($this->star, $this->chosenClient);
    }

    /**
     * When the Star last heard from the chosen client after the page was
     * opened, or null when it hasn't yet.
     */
    #[Computed]
    public function heardAt(): ?CarbonImmutable
    {
        return resolve(StarCallers::class)->lastHeardFrom($this->star, $this->chosenClient, $this->heardWhenOpened);
    }

    /**
     * Whether "Check it works" is still listening for a call: for ten
     * minutes after it started.
     */
    #[Computed]
    public function isListening(): bool
    {
        return now()->getTimestamp() - $this->listeningSince < self::LISTEN_SECONDS;
    }

    /**
     * The environment variable the setup reads the Star's token from.
     */
    #[Computed]
    public function tokenVariable(): string
    {
        return ClientSetup::tokenVariable($this->star);
    }

    /**
     * Save the Connections and details together, once both are valid, so
     * the Star matches the page, as the unsaved-changes bar described. The
     * Connections are written only when they differ from the Star as stored.
     */
    public function save(UpdateStar $updateStar): void
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);

        if ($this->changes->isEmpty()) {
            return;
        }

        $this->validate(
            [
                'connectionIds' => ['array'],
                'connectionIds.*' => ['integer', Rule::exists('connections', 'id')->where('user_id', $this->star->user_id)],
                'name' => ['required', 'string', 'max:100'],
                'description' => ['nullable', 'string', 'max:500'],
            ],
            ['connectionIds.*.exists' => __('Choose only your own Connections.')],
        );

        $changesConnections = $this->changes->connectionIds !== [];

        $updateStar->handle(
            $this->star,
            $this->name,
            $this->description === '' ? null : $this->description,
            $changesConnections ? array_map(intval(...), $this->connectionIds) : null,
        );

        unset($this->stats, $this->changes);
        $this->resetFields();

        Flux::toast(variant: 'success', text: $changesConnections
            ? __('Saved. The Star includes :count.', ['count' => trans_choice(':count Connection|:count Connections', count($this->connectionIds))])
            : __('Saved.'));
    }

    /**
     * Put the Connections and details back as stored.
     */
    public function discard(): void
    {
        unset($this->changes);
        $this->resetFields();
    }

    public function delete(DeleteStar $deleteStar): void
    {
        $deleteStar->handle($this->star);

        session()->flash('toast', ['variant' => 'success', 'text' => __('Deleted :name.', ['name' => $this->star->name])]);

        $this->redirectRoute('stars.index', navigate: true);
    }

    /**
     * Fill the Connections picker and the details from the Star as stored.
     */
    private function resetFields(): void
    {
        $this->name = $this->star->name;
        $this->description = $this->star->description ?? '';
        $this->connectionIds = array_values(array_map(
            fn (Connection $connection): string => (string) $connection->id,
            $this->star->connections()->orderBy('connections.id')->get()->all(),
        ));
        $this->resetValidation();
    }
};
