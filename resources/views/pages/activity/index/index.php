<?php

declare(strict_types=1);

use App\Actions\RefreshCatalog;
use App\Actions\SwitchStarPrompts;
use App\Actions\SwitchStarTools;
use App\Enums\ActivityKind;
use App\Enums\ActivityRange;
use App\Enums\ActivityStatus;
use App\Enums\DenialReason;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use App\Stars\DenialReasons;
use App\Stars\StarPrompt;
use App\Stars\StarTool;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
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
     * The session key that remembers the viewer's timezone, as their browser
     * reported it, so the next visit shows local times straight away.
     */
    private const string TIMEZONE_KEY = 'timezone';

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

    /**
     * How far back to look (an `ActivityRange` value).
     */
    #[Url(as: 'range', except: '24h')]
    public string $range = '24h';

    /**
     * The id of the user's entry whose details the panel shows, or empty
     * for none. Anything else, such as another user's entry, is ignored.
     */
    #[Url(as: 'entry', except: '')]
    public string $entry = '';

    /**
     * The entry whose refused tool or prompt was just switched on from the
     * panel, so the panel says it's on now.
     */
    #[Locked]
    public ?int $switchedOn = null;

    /**
     * Whether the viewer paused the live updates to read the list.
     */
    public bool $paused = false;

    /**
     * The timezone times and days are shown in: the viewer's browser's,
     * once it has said, otherwise UTC.
     */
    #[Locked]
    public string $timezone = 'UTC';

    public function mount(): void
    {
        $this->dropUnknownFilters();
        $this->dropUnknownEntry();

        $remembered = session(self::TIMEZONE_KEY);

        if (is_string($remembered) && $this->isTimezone($remembered)) {
            $this->timezone = $remembered;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['starFilter', 'connectionFilter', 'statusFilter', 'kindFilter', 'range'], true)) {
            $this->dropUnknownFilters();
            $this->resetPage();
        }

        if ($property === 'entry') {
            $this->switchedOn = null;
            $this->dropUnknownEntry();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('starFilter', 'connectionFilter', 'statusFilter', 'kindFilter');
        $this->resetPage();
    }

    /**
     * Show one of the user's entries in the panel.
     */
    public function selectEntry(int $entryId): void
    {
        if ($this->entry !== (string) $entryId) {
            $this->entry = (string) $entryId;
            $this->switchedOn = null;
            $this->dropUnknownEntry();
        }
    }

    public function closeEntry(): void
    {
        $this->entry = '';
        $this->switchedOn = null;
    }

    /**
     * Switch on, in its Star, the tool or prompt the selected entry's call
     * was refused for, through the same action as the Star's own switches.
     * Only one that is switched off now can be: a call refused for another
     * reason has nothing to switch.
     */
    public function switchOn(SwitchStarTools $switchStarTools, SwitchStarPrompts $switchStarPrompts): void
    {
        $entry = $this->selectedEntry ?? abort(404);
        $star = $this->user->stars()->find($entry->star_id);
        $switchedOff = $this->selectedSwitchedOff;

        if (! $star instanceof Star || $switchedOff === null) {
            unset($this->days, $this->selectedSwitchedOff, $this->selectedDenialReason);

            Flux::toast(variant: 'warning', text: __('There is nothing to switch on: it isn\'t switched off now.'));

            return;
        }

        if ($switchedOff instanceof StarTool) {
            $switchStarTools->handle($star, $switchedOff->connection, true, [$switchedOff->tool->name]);
        } else {
            $switchStarPrompts->handle($star, $switchedOff->connection, true, [$switchedOff->prompt->name]);
        }

        $this->switchedOn = $entry->id;

        unset($this->days, $this->selectedSwitchedOff, $this->selectedDenialReason);
    }

    /**
     * Reload the tools of the selected entry's Connection, as its page's
     * "Refresh tools" does, and say how it went.
     */
    public function refreshTools(RefreshCatalog $refreshCatalog): void
    {
        $entry = $this->selectedEntry ?? abort(404);
        $connection = $this->user->connections()->find($entry->connection_id) ?? abort(404);

        $loaded = $refreshCatalog->handle($connection);

        unset($this->selectedEntry);

        $result = match (true) {
            $loaded => trans_choice(':name: Nexus loaded :count tool.|:name: Nexus loaded :count tools.', $connection->tools()->count(), ['name' => $connection->name]),
            filled($connection->last_error) => __(':name: Nexus couldn\'t load the tools: :error', ['name' => $connection->name, 'error' => $connection->last_error]),
            default => __(':name: Nexus couldn\'t load the tools.', ['name' => $connection->name]),
        };

        $others = $connection->sameServiceConnections();
        $note = $others->isEmpty() ? '' : __('Only this account was refreshed, not :names.', [
            'names' => Arr::join($others->map(fn (Connection $other): string => $other->name)->all(), ', ', __(' and ')),
        ]);

        Flux::toast(variant: $loaded ? 'success' : 'danger', text: trim($result.' '.$note));
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    /**
     * Show times in the timezone the viewer's browser reports, and remember
     * it for their next visit. Anything that isn't a timezone PHP knows is
     * ignored.
     */
    public function useTimezone(string $timezone): void
    {
        if (! $this->isTimezone($timezone)) {
            return;
        }

        $this->timezone = $timezone;

        session()->put(self::TIMEZONE_KEY, $timezone);
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
     * The user's Star the filter names, if any.
     */
    #[Computed]
    public function selectedStar(): ?Star
    {
        return $this->stars->firstWhere('public_id', $this->starFilter);
    }

    /**
     * The user's Connection the filter names, if any.
     */
    #[Computed]
    public function selectedConnection(): ?Connection
    {
        return $this->connections->first(fn (Connection $connection): bool => (string) $connection->id === $this->connectionFilter);
    }

    #[Computed]
    public function selectedStatus(): ?ActivityStatus
    {
        return ActivityStatus::tryFrom($this->statusFilter);
    }

    #[Computed]
    public function selectedKind(): ?ActivityKind
    {
        return ActivityKind::tryFrom($this->kindFilter);
    }

    #[Computed]
    public function selectedRange(): ActivityRange
    {
        return ActivityRange::tryFrom($this->range) ?? ActivityRange::LastDay;
    }

    /**
     * Whether any filter is set. The range isn't a filter: there is always one.
     */
    #[Computed]
    public function isFiltered(): bool
    {
        return $this->starFilter !== '' || $this->connectionFilter !== '' || $this->statusFilter !== '' || $this->kindFilter !== '';
    }

    /**
     * Whether the user has any activity at all, whatever the filters and range.
     */
    #[Computed]
    public function hasActivity(): bool
    {
        return $this->user->activityEntries()->exists();
    }

    /**
     * Whether calls that match the filters happened before the range, but
     * within the longest one, so widening it would show them.
     */
    #[Computed]
    public function hasEarlierMatches(): bool
    {
        if ($this->selectedRange === ActivityRange::LastMonth) {
            return false;
        }

        $now = CarbonImmutable::now();

        return $this->matching()
            ->where('created_at', '>=', ActivityRange::LastMonth->start($now))
            ->where('created_at', '<', $this->selectedRange->start($now))
            ->exists();
    }

    /**
     * The user's entries in the range that match the filters, newest first.
     * A page past the last one, say from an old link, shows the last one
     * instead.
     *
     * @return LengthAwarePaginator<int, ActivityEntry>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $query = $this->matchingInRange()
            ->with(['star', 'connection'])
            ->latest()
            ->latest('id');

        $entries = (clone $query)->paginate(self::PER_PAGE);

        if ($entries->currentPage() > $entries->lastPage()) {
            $this->setPage($entries->lastPage());

            $entries = (clone $query)->paginate(self::PER_PAGE);
        }

        return $entries;
    }

    /**
     * This page's entries by the day they happened on, in the viewer's
     * timezone, newest first. Each day has its label, its UTC offset (both
     * of them on a day the clocks change), and a summary of the calls in
     * the range that matched the filters that whole day, not just on this
     * page: how many, and how many weren't OK. Each row has its entry, its
     * time in the viewer's timezone with the offset then and, when the call
     * wasn't OK, the label saying how it ended.
     *
     * @return list<array{date: string, label: string, zone: string, summary: string, rows: list<array{entry: ActivityEntry, at: CarbonImmutable, zone: string, problem: string|null}>}>
     */
    #[Computed]
    public function days(): array
    {
        $denialReasons = resolve(DenialReasons::class);
        $rowsByDate = [];

        foreach ($this->entries->items() as $entry) {
            $at = $entry->created_at->setTimezone($this->timezone);

            $rowsByDate[$at->toDateString()][] = [
                'entry' => $entry,
                'at' => $at,
                'zone' => $this->offsetLabel($at),
                'problem' => $entry->status === ActivityStatus::Ok ? null : ($denialReasons->reasonFor($entry)?->label($entry->kind) ?? $entry->status->label()),
            ];
        }

        $days = [];

        foreach ($rowsByDate as $date => $rows) {
            $start = $rows[0]['at']->startOfDay();
            $calls = $this->matchingInRange()
                ->where('created_at', '>=', $this->inAppTimezone($start))
                ->where('created_at', '<', $this->inAppTimezone($start->addDay()));
            $count = (clone $calls)->count();
            $notOk = $calls->where('status', '!=', ActivityStatus::Ok)->count();
            $summary = trans_choice(':count call|:count calls', $count, ['count' => number_format($count)]);

            $days[] = [
                'date' => $date,
                'label' => $this->dayLabel($start),
                'zone' => $this->dayZoneLabel($start),
                'summary' => $notOk === 0 ? $summary : __(':calls · :count not OK', ['calls' => $summary, 'count' => number_format($notOk)]),
                'rows' => $rows,
            ];
        }

        return $days;
    }

    /**
     * The user's entry the panel shows, with its Star and Connection, or
     * null when none is selected.
     */
    #[Computed]
    public function selectedEntry(): ?ActivityEntry
    {
        $id = filter_var($this->entry, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            return null;
        }

        return $this->user->activityEntries()->with(['star', 'connection'])->find($id);
    }

    /**
     * When the selected entry's call was made, in the viewer's timezone:
     * the day ("Today", "Yesterday" or the date) and the time to the
     * second, and the full date with its UTC offset.
     *
     * @return array{label: string, full: string}|null
     */
    #[Computed]
    public function selectedTime(): ?array
    {
        $entry = $this->selectedEntry;

        if (! $entry instanceof ActivityEntry) {
            return null;
        }

        $at = $entry->created_at->setTimezone($this->timezone);

        return [
            'label' => __(':day at :time', ['day' => $this->dayLabel($at->startOfDay()), 'time' => $at->format('H:i:s')]),
            'full' => $at->isoFormat('dddd, MMMM D, YYYY HH:mm:ss').' '.$this->offsetLabel($at),
        ];
    }

    /**
     * Why the selected entry's call was refused, as far as the entry and
     * its Star as it is now can tell, or null when it wasn't.
     */
    #[Computed]
    public function selectedDenialReason(): ?DenialReason
    {
        $entry = $this->selectedEntry;

        return $entry instanceof ActivityEntry ? resolve(DenialReasons::class)->reasonFor($entry) : null;
    }

    /**
     * The tool or prompt the selected entry's call was refused for, while
     * it is still switched off in its Star; otherwise null.
     */
    #[Computed]
    public function selectedSwitchedOff(): StarTool|StarPrompt|null
    {
        $entry = $this->selectedEntry;

        return $entry instanceof ActivityEntry ? resolve(DenialReasons::class)->switchedOff($entry) : null;
    }

    /**
     * When the list was last brought up to date, in the viewer's timezone.
     */
    #[Computed]
    public function updatedAt(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    #[Computed]
    public function retentionDays(): int
    {
        return ActivityEntry::retentionDays();
    }

    /**
     * The user's entries that match the filters, at any time.
     *
     * @return Builder<ActivityEntry>
     */
    private function matching(): Builder
    {
        $query = ActivityEntry::query()->whereBelongsTo($this->user);

        if (($star = $this->selectedStar) instanceof Star) {
            $query->whereBelongsTo($star);
        }

        if (($connection = $this->selectedConnection) instanceof Connection) {
            $query->whereBelongsTo($connection);
        }

        if (($status = $this->selectedStatus) instanceof ActivityStatus) {
            $query->where('status', $status);
        }

        if (($kind = $this->selectedKind) instanceof ActivityKind) {
            $query->where('kind', $kind);
        }

        return $query;
    }

    /**
     * The user's entries that match the filters, in the range.
     *
     * @return Builder<ActivityEntry>
     */
    private function matchingInRange(): Builder
    {
        return $this->matching()->where('created_at', '>=', $this->selectedRange->start(CarbonImmutable::now()));
    }

    /**
     * The moment in the timezone entries are stored in. Query bindings keep
     * a date's wall-clock time and drop its timezone.
     */
    private function inAppTimezone(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->setTimezone(config()->string('app.timezone'));
    }

    /**
     * "Today", "Yesterday" or the date (with its year when it isn't this
     * one), for a day in the viewer's timezone.
     */
    private function dayLabel(CarbonImmutable $day): string
    {
        $today = CarbonImmutable::now($this->timezone)->startOfDay();

        return match ($day->toDateString()) {
            $today->toDateString() => __('Today'),
            $today->subDay()->toDateString() => __('Yesterday'),
            default => $day->isoFormat($day->year === $today->year ? 'dddd, MMMM D' : 'dddd, MMMM D, YYYY'),
        };
    }

    /**
     * The viewer's offset from UTC through a day, such as "UTC+08:00", or
     * both offsets on a day the clocks change ("UTC+12:00 → UTC+13:00").
     */
    private function dayZoneLabel(CarbonImmutable $day): string
    {
        $start = $this->offsetLabel($day);
        $end = $this->offsetLabel($day->endOfDay());

        return $start === $end ? $start : __(':start → :end', ['start' => $start, 'end' => $end]);
    }

    /**
     * The viewer's offset from UTC at that moment, such as "UTC+08:00", or
     * just "UTC".
     */
    private function offsetLabel(CarbonImmutable $moment): string
    {
        $offset = $moment->format('P');

        return $offset === '+00:00' ? 'UTC' : 'UTC'.$offset;
    }

    /**
     * Whether PHP knows this timezone, including the older names some
     * browsers still report (such as "Asia/Calcutta").
     */
    private function isTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    /**
     * Clear the selected entry when it isn't one of the user's, such as
     * another user's entry or one since pruned.
     */
    private function dropUnknownEntry(): void
    {
        unset($this->selectedEntry, $this->selectedTime, $this->selectedDenialReason, $this->selectedSwitchedOff);

        if (! $this->selectedEntry instanceof ActivityEntry) {
            $this->entry = '';
        }
    }

    /**
     * Clear any filter that names no Star, Connection, status or kind of
     * the user's, such as another user's Star or one since deleted, and an
     * unknown range, so the filters on screen match what is shown.
     */
    private function dropUnknownFilters(): void
    {
        if (! $this->selectedStar instanceof Star) {
            $this->starFilter = '';
        }

        if (! $this->selectedConnection instanceof Connection) {
            $this->connectionFilter = '';
        }

        if (! $this->selectedStatus instanceof ActivityStatus) {
            $this->statusFilter = '';
        }

        if (! $this->selectedKind instanceof ActivityKind) {
            $this->kindFilter = '';
        }

        if (! ActivityRange::tryFrom($this->range) instanceof ActivityRange) {
            $this->range = ActivityRange::LastDay->value;
        }
    }
};
