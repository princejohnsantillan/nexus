{{--
    The tool details flyout (a flux:modal flyout named "tool-details"):
    everything about one tool from an App\Stars\ToolDetails, or nothing
    while `details` is null. The page opens it from an
    <x-tool-flyout.trigger>, keeps the tool's catalog id in `detailsToolId`
    and shows the modal; closing it forgets the id and puts focus back on
    the tool's trigger. A Star's Tools page puts the tool's switch in the
    `switch` slot.
--}}
@props([
    'details' => null,
])

@php
$hints = [
    ['label' => __('Read-only'), 'value' => $details?->tool->read_only, 'tone' => 'success'],
    ['label' => __('Destructive'), 'value' => $details?->tool->destructive, 'tone' => 'danger'],
    ['label' => __('Idempotent'), 'value' => $details?->tool->idempotent, 'tone' => null],
    ['label' => __('Open-world'), 'value' => $details?->tool->open_world, 'tone' => 'warning'],
];
$sectionHeading = 'text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400';
$pill = 'inline-flex h-5 items-center rounded-md px-1.5 text-xs font-medium whitespace-nowrap';
$neutralPill = 'bg-zinc-50 text-zinc-600 ring-1 ring-zinc-200 ring-inset dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10';
@endphp

<flux:modal
    name="tool-details"
    variant="flyout"
    class="w-full max-w-full p-0! sm:w-[34rem]"
    x-on:close="document.getElementById('tool-details-trigger-' + $wire.detailsToolId)?.focus({ preventScroll: true }); $wire.detailsToolId = null"
>
    @if ($details !== null)
        <div x-init="$el.closest('dialog').setAttribute('aria-labelledby', 'tool-details-heading')" class="flex min-h-dvh flex-col">
            <div class="border-b border-zinc-200 px-6 pt-6 pb-5 dark:border-white/10">
                <div class="flex min-w-0 items-center gap-2 pe-10">
                    <x-tool-risk :risk="App\Enums\ToolRisk::of($details->tool)" />
                    <span class="flex min-w-0 items-center gap-1.5 text-sm text-zinc-500 dark:text-zinc-400">
                        <x-connector-logo :connector="$details->tool->connection->connector()" size="xs" />
                        <span class="truncate">{{ $details->tool->connection->name }}</span>
                    </span>
                </div>

                <flux:heading size="lg" level="2" id="tool-details-heading" class="mt-3 font-mono break-all">{{ $details->exposedName }}</flux:heading>

                @if (filled($details->tool->title))
                    <flux:text class="mt-1">{{ $details->tool->title }}</flux:text>
                @endif
            </div>

            @isset($switch)
                <div class="border-b border-zinc-200 bg-accent-wash px-6 py-4 dark:border-white/10">
                    {{ $switch }}
                </div>
            @endisset

            <div class="flex-1 space-y-8 px-6 py-6">
                <section aria-labelledby="tool-details-description">
                    <h3 id="tool-details-description" class="{{ $sectionHeading }}">{{ __('Description') }}</h3>

                    @if (filled($details->tool->description))
                        <flux:text class="mt-2 break-words whitespace-pre-line text-zinc-950! dark:text-white!">{{ $details->tool->description }}</flux:text>
                    @else
                        <flux:text class="mt-2">{{ __('The server gave no description.') }}</flux:text>
                    @endif

                    <dl class="mt-4 divide-y divide-zinc-200 border-y border-zinc-200 text-sm dark:divide-white/10 dark:border-white/10">
                        <div class="grid grid-cols-[8rem_minmax(0,1fr)] gap-3 py-2.5">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Server\'s name') }}</dt>
                            <dd class="font-mono break-all text-zinc-950 dark:text-white">{{ $details->tool->name }}</dd>
                        </div>
                        <div class="grid grid-cols-[8rem_minmax(0,1fr)] gap-3 py-2.5">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Connection') }}</dt>
                            <dd class="min-w-0 text-zinc-950 dark:text-white">
                                <flux:link :href="route('connections.show', $details->tool->connection)" wire:navigate>{{ $details->tool->connection->name }}</flux:link>
                                <span class="text-zinc-500 dark:text-zinc-400"><span class="font-mono">· {{ $details->tool->connection->handle }}</span> <x-account-label :connection="$details->tool->connection" separated /></span>
                            </dd>
                        </div>
                        @foreach ($hints as $hint)
                            <div class="grid grid-cols-[8rem_minmax(0,1fr)] items-center gap-3 py-2.5">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ $hint['label'] }}</dt>
                                <dd><x-tool-hint :value="$hint['value']" :tone="$hint['tone']" /></dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section aria-labelledby="tool-details-parameters">
                    <h3 id="tool-details-parameters" class="{{ $sectionHeading }}">{{ __('Parameters') }}</h3>

                    @php($parameters = $details->tool->parameters())

                    @if ($parameters === [])
                        <x-empty-state compact icon="variable" :heading="__('No parameters')" class="mt-3">
                            {{ __('Its input schema lists none, so clients call it without arguments.') }}
                        </x-empty-state>
                    @else
                        <ul class="mt-3 divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-white/10 dark:border-white/10">
                            @foreach ($parameters as $parameter)
                                <li class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="font-mono text-sm font-medium break-all text-zinc-950 dark:text-white">{{ $parameter['name'] }}</span>
                                        <span class="font-mono text-xs break-all text-zinc-500 dark:text-zinc-400">{{ $parameter['type'] }}</span>
                                        <span @class([$pill, 'bg-warning-wash text-warning' => $parameter['required'], $neutralPill => ! $parameter['required']])>{{ $parameter['required'] ? __('Required') : __('Optional') }}</span>
                                    </div>

                                    @if ($parameter['description'] !== null)
                                        <flux:text size="sm" class="mt-1 break-words whitespace-pre-line">{{ $parameter['description'] }}</flux:text>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section aria-labelledby="tool-details-stars">
                    <h3 id="tool-details-stars" class="{{ $sectionHeading }}">{{ __('In your Stars') }}</h3>

                    @if ($details->stars === [])
                        <x-empty-state compact icon="star" :heading="__('In no Star yet')" class="mt-3">
                            {{ __('Add :connection to a Star to let its clients use this tool.', ['connection' => $details->tool->connection->name]) }}
                        </x-empty-state>
                    @else
                        <ul class="mt-3 divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-white/10 dark:border-white/10">
                            @foreach ($details->stars as $inStar)
                                <li class="flex items-center justify-between gap-3 px-4 py-2.5" wire:key="tool-details-star-{{ $inStar['star']->id }}">
                                    <flux:link :href="route('stars.tools', $inStar['star'])" wire:navigate class="min-w-0 truncate text-sm font-medium">{{ $inStar['star']->name }}</flux:link>

                                    <span class="flex shrink-0 items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $inStar['followsPolicy'] ? __('Policy') : __('Your choice') }}
                                        <span @class([$pill, 'bg-accent-wash text-accent-content' => $inStar['enabled'], $neutralPill => ! $inStar['enabled']])>{{ $inStar['enabled'] ? __('On') : __('Off') }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section aria-labelledby="tool-details-calls">
                    <h3 id="tool-details-calls" class="{{ $sectionHeading }}">{{ __('Recent calls') }}</h3>

                    @if ($details->recentCalls->isEmpty())
                        <x-empty-state compact icon="queue-list" :heading="__('No calls yet')" class="mt-3">
                            {{ trans_choice('Calls from the last :days day appear here.|Calls from the last :days days appear here.', App\Models\ActivityEntry::retentionDays(), ['days' => App\Models\ActivityEntry::retentionDays()]) }}
                        </x-empty-state>
                    @else
                        <ul class="mt-3 divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-white/10 dark:border-white/10">
                            @foreach ($details->recentCalls as $call)
                                <li class="flex items-center gap-3 px-4 py-2.5" wire:key="tool-details-call-{{ $call->id }}">
                                    <x-activity-status-icon :status="$call->status" />

                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-zinc-950 dark:text-white">
                                            @if ($call->star !== null)
                                                {{ $call->star->name }}
                                            @else
                                                <span class="font-normal text-zinc-500 italic dark:text-zinc-400">{{ __('Deleted Star') }}</span>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">
                                            <time datetime="{{ $call->created_at->toIso8601String() }}" title="{{ $call->created_at->isoFormat('dddd, MMMM D, YYYY HH:mm:ss') }} {{ $call->created_at->format('T') }}">{{ $call->created_at->diffForHumans() }}</time>
                                            ·
                                            <span @class([
                                                'font-medium',
                                                'text-success' => $call->status === App\Enums\ActivityStatus::Ok,
                                                'text-zinc-950 dark:text-white' => $call->status === App\Enums\ActivityStatus::Denied,
                                                'text-warning' => in_array($call->status, [App\Enums\ActivityStatus::NeedsAuth, App\Enums\ActivityStatus::Timeout], true),
                                                'text-danger' => $call->status === App\Enums\ActivityStatus::Error,
                                            ])>{{ $call->status->label() }}</span>
                                        </p>
                                    </div>

                                    <span class="shrink-0 font-mono text-sm text-zinc-600 tabular-nums dark:text-zinc-400">{{ $call->durationForHumans() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            <div class="flex items-center gap-2 border-t border-zinc-200 bg-zinc-50 px-6 py-3 text-xs text-zinc-500 dark:border-white/10 dark:bg-black/15 dark:text-zinc-400">
                <flux:icon.lock-closed variant="micro" class="shrink-0" />
                {{ __('Nexus never stores a call\'s arguments or results.') }}
            </div>
        </div>
    @endif
</flux:modal>
