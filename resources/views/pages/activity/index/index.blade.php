<div
    class="mx-auto w-full max-w-5xl"
    x-data
    x-init="const zone = Intl.DateTimeFormat().resolvedOptions().timeZone; if (zone && zone !== $wire.timezone) $wire.useTimezone(zone)"
>
    @unless ($paused)
        <div wire:poll.5s></div>
    @endunless

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('Activity') }}</flux:heading>
            <flux:text class="mt-2">
                {{ trans_choice('Every tool call and prompt fetch through your Stars, kept for :days day.|Every tool call and prompt fetch through your Stars, kept for :days days.', $this->retentionDays, ['days' => $this->retentionDays]) }}
                {{ __('Arguments and results are never stored.') }}
            </flux:text>
        </div>

        <div class="flex shrink-0 items-center gap-3">
            <p class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                <span @class([
                    'size-2 shrink-0 rounded-full',
                    'bg-success ring-3 ring-success-wash' => ! $paused,
                    'bg-zinc-400 dark:bg-zinc-500' => $paused,
                ])></span>
                <span>{{ $paused ? __('Paused · updated :time', ['time' => $this->updatedAt->format('H:i')]) : __('Live · updated :time', ['time' => $this->updatedAt->format('H:i')]) }}</span>
            </p>

            @if ($paused)
                <flux:button size="sm" icon="play" wire:click="resume">{{ __('Resume') }}</flux:button>
            @else
                <flux:button size="sm" icon="pause" wire:click="pause">{{ __('Pause') }}</flux:button>
            @endif
        </div>
    </div>

    @if (! $this->hasActivity)
        <x-empty-state icon="queue-list" :heading="__('No activity yet')" class="mt-8">
            {{ __('When an AI client uses one of your Stars, each call appears here with its time, status and duration. Arguments and results are never stored.') }}
        </x-empty-state>
    @else
        <div class="mt-6 flex flex-wrap items-center gap-2">
            <x-filter-chip :label="__('Star')" :value="$this->selectedStar?->name">
                <flux:menu>
                    <flux:menu.radio.group wire:model.live="starFilter">
                        @forelse ($this->stars as $star)
                            <flux:menu.radio :value="$star->public_id" wire:key="star-{{ $star->id }}">{{ $star->name }}</flux:menu.radio>
                        @empty
                            <flux:menu.item disabled>{{ __('No Stars') }}</flux:menu.item>
                        @endforelse
                    </flux:menu.radio.group>

                    @if ($this->selectedStar !== null)
                        <flux:menu.separator />
                        <flux:menu.item icon="x-mark" wire:click="$set('starFilter', '')">{{ __('All Stars') }}</flux:menu.item>
                    @endif
                </flux:menu>
            </x-filter-chip>

            <x-filter-chip :label="__('Connection')" :value="$this->selectedConnection?->name">
                <flux:menu>
                    <flux:menu.radio.group wire:model.live="connectionFilter">
                        @forelse ($this->connections as $connection)
                            <flux:menu.radio :value="(string) $connection->id" wire:key="connection-{{ $connection->id }}">
                                {{ $connection->name }}
                                <span class="ms-2 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $connection->handle }}</span>
                            </flux:menu.radio>
                        @empty
                            <flux:menu.item disabled>{{ __('No Connections') }}</flux:menu.item>
                        @endforelse
                    </flux:menu.radio.group>

                    @if ($this->selectedConnection !== null)
                        <flux:menu.separator />
                        <flux:menu.item icon="x-mark" wire:click="$set('connectionFilter', '')">{{ __('All Connections') }}</flux:menu.item>
                    @endif
                </flux:menu>
            </x-filter-chip>

            <x-filter-chip :label="__('Status')" :value="$this->selectedStatus?->label()">
                <flux:menu>
                    <flux:menu.radio.group wire:model.live="statusFilter">
                        @foreach (App\Enums\ActivityStatus::cases() as $status)
                            <flux:menu.radio :value="$status->value" wire:key="status-{{ $status->value }}">{{ $status->label() }}</flux:menu.radio>
                        @endforeach
                    </flux:menu.radio.group>

                    @if ($this->selectedStatus !== null)
                        <flux:menu.separator />
                        <flux:menu.item icon="x-mark" wire:click="$set('statusFilter', '')">{{ __('Any status') }}</flux:menu.item>
                    @endif
                </flux:menu>
            </x-filter-chip>

            <x-filter-chip :label="__('Kind')" :value="$this->selectedKind?->label()">
                <flux:menu>
                    <flux:menu.radio.group wire:model.live="kindFilter">
                        @foreach (App\Enums\ActivityKind::cases() as $kind)
                            <flux:menu.radio :value="$kind->value" wire:key="kind-{{ $kind->value }}">{{ $kind->label() }}</flux:menu.radio>
                        @endforeach
                    </flux:menu.radio.group>

                    @if ($this->selectedKind !== null)
                        <flux:menu.separator />
                        <flux:menu.item icon="x-mark" wire:click="$set('kindFilter', '')">{{ __('Any kind') }}</flux:menu.item>
                    @endif
                </flux:menu>
            </x-filter-chip>

            @if ($this->isFiltered)
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear') }}</flux:button>
            @endif

            <flux:radio.group wire:model.live="range" variant="segmented" size="sm" class="ms-auto" :aria-label="__('Time range')">
                @foreach (App\Enums\ActivityRange::cases() as $range)
                    <flux:radio :value="$range->value" :label="$range->label()" wire:key="range-{{ $range->value }}" />
                @endforeach
            </flux:radio.group>
        </div>

        @if ($this->entries->isEmpty())
            <x-empty-state icon="funnel" :heading="__('No matching activity')" class="mt-4">
                @if ($this->isFiltered)
                    {{ __('No calls match these filters in the last :range.', ['range' => $this->selectedRange->description()]) }}
                @else
                    {{ __('No calls in the last :range.', ['range' => $this->selectedRange->description()]) }}
                @endif

                @if ($this->hasEarlierMatches || $this->isFiltered)
                    <x-slot:actions>
                        @if ($this->hasEarlierMatches)
                            <flux:button wire:click="$set('range', '{{ App\Enums\ActivityRange::LastMonth->value }}')">{{ __('Show the last 30 days') }}</flux:button>
                        @endif

                        @if ($this->isFiltered)
                            <flux:button wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
                        @endif
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-white/[4%]">
                @foreach ($this->days as $day)
                    <section class="not-first:border-t not-first:border-zinc-200 dark:not-first:border-white/10" aria-labelledby="day-{{ $day['date'] }}" wire:key="day-{{ $day['date'] }}">
                        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-zinc-200 bg-zinc-50 px-4 py-2 text-xs font-medium text-zinc-600 dark:border-white/10 dark:bg-black/15 dark:text-zinc-400">
                            <h2 id="day-{{ $day['date'] }}">
                                <span class="uppercase tracking-wide">{{ $day['label'] }}</span>
                                <span class="ms-1.5 font-normal text-zinc-500 dark:text-zinc-400">{{ $day['zone'] }}</span>
                            </h2>

                            <p class="font-normal">{{ $day['summary'] }}</p>
                        </div>

                        <ul role="list" class="divide-y divide-zinc-200 dark:divide-white/10">
                            @foreach ($day['rows'] as $row)
                                <li
                                    wire:key="entry-{{ $row['entry']->id }}"
                                    data-entry="{{ $row['entry']->id }}"
                                    @class([
                                        'grid grid-cols-[18px_2.75rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-4 py-2.75 sm:grid-cols-[18px_2.75rem_minmax(0,1fr)_9rem_4.5rem] sm:gap-x-3.5',
                                        'bg-danger-wash/50' => $row['entry']->status === App\Enums\ActivityStatus::Error,
                                    ])
                                >
                                    <x-activity-status-icon :status="$row['entry']->status" />

                                    <time
                                        datetime="{{ $row['entry']->created_at->toIso8601String() }}"
                                        title="{{ $row['at']->isoFormat('dddd, MMMM D, YYYY HH:mm:ss') }} {{ $row['zone'] }}"
                                        class="font-mono text-sm text-zinc-600 dark:text-zinc-400"
                                    >{{ $row['at']->format('H:i') }}</time>

                                    <div class="min-w-0">
                                        @if ($row['entry']->exposed_name !== null)
                                            <p class="font-mono text-sm/4 font-medium break-all text-zinc-950 sm:truncate dark:text-white" title="{{ $row['entry']->exposed_name }}">{{ $row['entry']->exposed_name }}</p>
                                        @else
                                            <p class="text-sm/4 text-zinc-500 italic dark:text-zinc-400">{{ __('No name') }}</p>
                                        @endif

                                        <p class="mt-0.5 text-xs text-zinc-600 sm:truncate dark:text-zinc-400">
                                            @if ($row['entry']->star !== null)
                                                <a href="{{ route('stars.show', $row['entry']->star) }}" wire:navigate class="hover:text-zinc-950 hover:underline dark:hover:text-white">{{ $row['entry']->star->name }}</a>
                                            @else
                                                <span class="italic">{{ __('Deleted Star') }}</span>
                                            @endif

                                            @if ($row['entry']->connection !== null)
                                                · <a href="{{ route('connections.show', $row['entry']->connection) }}" wire:navigate class="hover:text-zinc-950 hover:underline dark:hover:text-white">{{ $row['entry']->connection->name }}</a>
                                            @elseif ($row['entry']->connectionWasDeleted())
                                                · <span class="italic">{{ __('Deleted Connection') }}</span>
                                            @endif

                                            · {{ $row['entry']->client_name ?? $row['entry']->via->label() }}

                                            @if ($row['entry']->kind === App\Enums\ActivityKind::Prompt)
                                                · {{ __('prompt') }}
                                            @endif
                                        </p>
                                    </div>

                                    @if ($row['problem'] !== null)
                                        <p @class([
                                            'col-span-2 col-start-3 row-start-2 text-xs font-medium sm:col-span-1 sm:col-start-4 sm:row-start-1',
                                            'text-zinc-950 dark:text-white' => $row['entry']->status === App\Enums\ActivityStatus::Denied,
                                            'text-warning' => in_array($row['entry']->status, [App\Enums\ActivityStatus::NeedsAuth, App\Enums\ActivityStatus::Timeout], true),
                                            'text-danger' => $row['entry']->status === App\Enums\ActivityStatus::Error,
                                        ])>{{ $row['problem'] }}</p>
                                    @else
                                        <p class="sr-only">{{ App\Enums\ActivityStatus::Ok->label() }}</p>
                                    @endif

                                    <p class="col-start-4 row-start-1 text-right font-mono text-sm text-zinc-600 tabular-nums sm:col-start-5 dark:text-zinc-400">{{ $row['entry']->durationForHumans() }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>

            @if ($this->entries->hasPages())
                <flux:pagination :paginator="$this->entries" class="mt-4" />
            @endif
        @endif
    @endif
</div>
