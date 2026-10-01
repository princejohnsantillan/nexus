<div class="mx-auto w-full max-w-5xl">
    <flux:heading size="xl" level="1">{{ __('Activity') }}</flux:heading>
    <flux:text class="mt-2">
        {{ __('Every tool call and prompt fetch made through your Stars.') }}
        {{ trans_choice('Nexus keeps each entry for :days day, then removes it.|Nexus keeps each entry for :days days, then removes it.', $this->retentionDays, ['days' => $this->retentionDays]) }}
    </flux:text>

    <flux:separator variant="subtle" class="my-6" />

    @if (! $this->hasActivity)
        <x-empty-state icon="queue-list" :heading="__('No activity yet')">
            {{ __('When an AI client uses one of your Stars, each call appears here with its time, status and duration. Arguments and results are never stored.') }}
        </x-empty-state>
    @else
        <div class="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:select wire:model.live="starFilter" :label="__('Star')">
                <flux:select.option value="">{{ __('All Stars') }}</flux:select.option>

                @foreach ($this->stars as $star)
                    <flux:select.option :value="$star->public_id" wire:key="star-{{ $star->id }}">{{ $star->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="connectionFilter" :label="__('Connection')">
                <flux:select.option value="">{{ __('All Connections') }}</flux:select.option>

                @foreach ($this->connections as $connection)
                    <flux:select.option :value="$connection->id" wire:key="connection-{{ $connection->id }}">{{ $connection->name }} ({{ $connection->handle }})</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statusFilter" :label="__('Status')">
                <flux:select.option value="">{{ __('Any status') }}</flux:select.option>

                @foreach (App\Enums\ActivityStatus::cases() as $status)
                    <flux:select.option :value="$status->value" wire:key="status-{{ $status->value }}">{{ $status->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="kindFilter" :label="__('Kind')">
                <flux:select.option value="">{{ __('Any kind') }}</flux:select.option>

                @foreach (App\Enums\ActivityKind::cases() as $kind)
                    <flux:select.option :value="$kind->value" wire:key="kind-{{ $kind->value }}">{{ $kind->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($this->isFiltered && $this->entries->isNotEmpty())
            <div class="mt-3 flex justify-end">
                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
            </div>
        @endif

        @if ($this->entries->isEmpty())
            <x-empty-state icon="funnel" :heading="__('No matching activity')" class="mt-6">
                {{ __('No calls match these filters.') }}

                <x-slot:actions>
                    <flux:button wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
                </x-slot:actions>
            </x-empty-state>
        @else
            <flux:table :paginate="$this->entries" class="mt-6">
                <flux:table.columns>
                    <flux:table.column>{{ __('Time') }}</flux:table.column>
                    <flux:table.column>{{ __('Star') }}</flux:table.column>
                    <flux:table.column>{{ __('Connection') }}</flux:table.column>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Kind') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Duration') }}</flux:table.column>
                    <flux:table.column>{{ __('Via') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->entries as $entry)
                        <flux:table.row :key="$entry->id">
                            <flux:table.cell>
                                <time datetime="{{ $entry->created_at->toIso8601String() }}" title="{{ $entry->created_at->toDayDateTimeString() }}">{{ $entry->created_at->diffForHumans() }}</time>
                            </flux:table.cell>
                            <flux:table.cell class="max-w-40 truncate">
                                @if ($entry->star !== null)
                                    <flux:link :href="route('stars.show', $entry->star)" wire:navigate class="font-medium">{{ $entry->star->name }}</flux:link>
                                @else
                                    <flux:text size="sm" class="text-zinc-400 italic dark:text-zinc-500">{{ __('Deleted Star') }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-w-48 truncate">
                                @if ($entry->connection !== null)
                                    <div class="flex items-center gap-2">
                                        <x-connector-logo :connector="$entry->connection->connector()" size="xs" />
                                        <flux:link :href="route('connections.show', $entry->connection)" wire:navigate>{{ $entry->connection->name }}</flux:link>
                                    </div>
                                @elseif ($entry->connectionWasDeleted())
                                    <flux:text size="sm" class="text-zinc-400 italic dark:text-zinc-500">{{ __('Deleted Connection') }}</flux:text>
                                @else
                                    <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('None') }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-w-64 truncate font-mono">
                                @if ($entry->exposed_name !== null)
                                    <span title="{{ $entry->exposed_name }}">{{ $entry->exposed_name }}</span>
                                @else
                                    <flux:text size="sm" class="font-sans text-zinc-400 dark:text-zinc-500">{{ __('No name') }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $entry->kind->label() }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$entry->status->color()">{{ $entry->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="tabular-nums">{{ __(':duration ms', ['duration' => number_format($entry->duration_ms)]) }}</flux:table.cell>
                            <flux:table.cell class="max-w-40 truncate">
                                {{ $entry->via->label() }}

                                @if (filled($entry->client_name))
                                    <flux:text size="sm" class="mt-0.5 truncate">{{ $entry->client_name }}</flux:text>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    @endif
</div>
