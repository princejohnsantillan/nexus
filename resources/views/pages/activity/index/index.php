<?php

declare(strict_types=1);

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

return new #[Title('Activity')] class extends Component
{
    use WithPagination;

    /**
     * How many entries each page shows.
     */
    private const int PER_PAGE = 25;

    /**
     * Shows only calls through the user's Star with this public id.
     */
    #[Url(as: 'star', except: '')]
    public string $starFilter = '';

    /**
     * Shows only calls to the user's Connection with this id.
     */
    #[Url(as: 'connection', except: '')]
    public string $connectionFilter = '';

    /**
     * Shows only calls that ended this way (an `ActivityStatus` value).
     */
    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    /**
     * Shows only calls of this kind (an `ActivityKind` value).
     */
    #[Url(as: 'kind', except: '')]
    public string $kindFilter = '';

    public function mount(): void
    {
        $this->dropUnknownFilters();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['starFilter', 'connectionFilter', 'statusFilter', 'kindFilter'], true)) {
            $this->dropUnknownFilters();
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('starFilter', 'connectionFilter', 'statusFilter', 'kindFilter');
        $this->resetPage();
    }

    #[Computed]
    public function user(): User
    {
        return Auth::user() ?? throw new AuthenticationException;
    }

    /**
     * The user's Stars, to filter by.
     *
     * @return Collection<int, Star>
     */
    #[Computed]
    public function stars(): Collection
    {
        return $this->user->stars()->orderBy('name')->orderBy('id')->get();
    }

    /**
     * The user's Connections, to filter by.
     *
     * @return Collection<int, Connection>
     */
    #[Computed]
    public function connections(): Collection
    {
        return $this->user->connections()->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Whether any filter is set.
     */
    #[Computed]
    public function isFiltered(): bool
    {
        return $this->starFilter !== '' || $this->connectionFilter !== '' || $this->statusFilter !== '' || $this->kindFilter !== '';
    }

    /**
     * Whether the user has any activity at all, whatever the filters.
     */
    #[Computed]
    public function hasActivity(): bool
    {
        return $this->user->activityEntries()->exists();
    }

    /**
     * The user's entries that match the filters, newest first. A page past
     * the last one, say from an old link, shows the last one instead.
     *
     * @return LengthAwarePaginator<int, ActivityEntry>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $query = ActivityEntry::query()
            ->whereBelongsTo($this->user)
            ->with(['star', 'connection'])
            ->latest()
            ->latest('id');

        if (($star = $this->selectedStar()) instanceof Star) {
            $query->whereBelongsTo($star);
        }

        if (($connection = $this->selectedConnection()) instanceof Connection) {
            $query->whereBelongsTo($connection);
        }

        if (($status = ActivityStatus::tryFrom($this->statusFilter)) instanceof ActivityStatus) {
            $query->where('status', $status);
        }

        if (($kind = ActivityKind::tryFrom($this->kindFilter)) instanceof ActivityKind) {
            $query->where('kind', $kind);
        }

        $entries = (clone $query)->paginate(self::PER_PAGE);

        if ($entries->currentPage() > $entries->lastPage()) {
            $this->setPage($entries->lastPage());

            $entries = (clone $query)->paginate(self::PER_PAGE);
        }

        return $entries;
    }

    #[Computed]
    public function retentionDays(): int
    {
        return ActivityEntry::retentionDays();
    }

    /**
     * The user's Star the filter names, if any.
     */
    private function selectedStar(): ?Star
    {
        return $this->stars->firstWhere('public_id', $this->starFilter);
    }

    /**
     * The user's Connection the filter names, if any.
     */
    private function selectedConnection(): ?Connection
    {
        return $this->connections->first(fn (Connection $connection): bool => (string) $connection->id === $this->connectionFilter);
    }

    /**
     * Clear any filter that names no Star, Connection, status or kind of
     * the user's, such as another user's Star or one since deleted, so the
     * filters on screen match what is shown.
     */
    private function dropUnknownFilters(): void
    {
        if (! $this->selectedStar() instanceof Star) {
            $this->starFilter = '';
        }

        if (! $this->selectedConnection() instanceof Connection) {
            $this->connectionFilter = '';
        }

        if (! ActivityStatus::tryFrom($this->statusFilter) instanceof ActivityStatus) {
            $this->statusFilter = '';
        }

        if (! ActivityKind::tryFrom($this->kindFilter) instanceof ActivityKind) {
            $this->kindFilter = '';
        }
    }
};
