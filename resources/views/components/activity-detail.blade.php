{{--
    One Activity entry in detail, for the panel beside the log and its
    flyout: how the call ended and when, the fix for that ending, and what
    the entry stores. It shows only what Nexus keeps: never the arguments,
    the result or anything the server said. Its close button and fixes call
    the Activity page's closeEntry, switchOn and refreshTools.

    - `entry`: the ActivityEntry, with its Star and Connection loaded.
    - `time`: when the call was made, as the page's selectedTime gives it.
    - `denialReason`: why the call was refused (App\Enums\DenialReason), or null.
    - `switchedOff`: the StarTool or StarPrompt the call was refused for while it is still off, or null.
    - `switchedOn`: whether it was just switched on from this panel.
--}}
@props([
    'entry',
    'time',
    'denialReason' => null,
    'switchedOff' => null,
    'switchedOn' => false,
])

@php
    $star = $entry->star;
    $connection = $entry->connection;
    $isPrompt = $entry->kind === App\Enums\ActivityKind::Prompt;
    $client = $entry->client_name ?? __('A client');
    $listRoute = $isPrompt ? 'stars.prompts' : 'stars.tools';
    $listLabel = $star !== null ? ($isPrompt ? __('Open :star\'s prompts', ['star' => $star->name]) : __('Open :star\'s tools', ['star' => $star->name])) : null;
@endphp

<section {{ $attributes->class('flex flex-col') }} aria-label="{{ __('Call details') }}" data-activity-detail="{{ $entry->id }}">
    <div class="flex flex-col gap-2.5 border-b border-zinc-200 px-5 pt-4.5 pb-4 dark:border-white/10">
        <div class="flex items-center gap-2">
            <span @class([
                'inline-flex h-5.5 shrink-0 items-center rounded-full px-2 text-xs font-medium whitespace-nowrap',
                'bg-success-wash text-success' => $entry->status === App\Enums\ActivityStatus::Ok,
                'bg-zinc-50 text-zinc-950 ring-1 ring-zinc-300 ring-inset dark:bg-white/5 dark:text-white dark:ring-white/20' => $entry->status === App\Enums\ActivityStatus::Denied,
                'bg-warning-wash text-warning' => in_array($entry->status, [App\Enums\ActivityStatus::NeedsAuth, App\Enums\ActivityStatus::Timeout], true),
                'bg-danger-wash text-danger' => $entry->status === App\Enums\ActivityStatus::Error,
            ])>{{ $entry->status->label() }}</span>

            <time datetime="{{ $entry->created_at->toIso8601String() }}" title="{{ $time['full'] }}" class="min-w-0 flex-1 truncate text-sm text-zinc-600 dark:text-zinc-400">{{ $time['label'] }}</time>

            <flux:button variant="subtle" size="sm" icon="x-mark" square wire:click="closeEntry" :aria-label="__('Close details')" class="-me-2 shrink-0" />
        </div>

        @if ($entry->exposed_name !== null)
            <h2 class="font-mono text-base/6 font-semibold break-all text-zinc-950 dark:text-white">{{ $entry->exposed_name }}</h2>
        @else
            <h2 class="text-base/6 text-zinc-500 italic dark:text-zinc-400">{{ __('No name') }}</h2>
        @endif
    </div>

    @if ($entry->status !== App\Enums\ActivityStatus::Ok)
        <div class="flex flex-col gap-3 border-b border-accent/15 bg-accent-wash px-5 py-4 dark:border-white/10" data-fix>
            <div class="flex flex-col gap-1">
                @if ($entry->status === App\Enums\ActivityStatus::Denied)
                    @if ($switchedOff !== null && $star !== null)
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm/5 font-semibold text-zinc-950 dark:text-white">
                                {{ $isPrompt ? __('This prompt is off in :star', ['star' => $star->name]) : __('This tool is off in :star', ['star' => $star->name]) }}
                            </h3>

                            @if ($switchedOff instanceof App\Stars\StarTool)
                                <x-tool-risk :risk="App\Enums\ToolRisk::of($switchedOff->tool)" class="bg-white! dark:bg-white/10!" />
                            @endif
                        </div>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $isPrompt
                                ? __(':client tried to get it. Switch it on only if :star\'s clients should be able to use it.', ['client' => $client, 'star' => $star->name])
                                : __(':client tried to call it. Switch it on only if :star\'s clients should be able to use it.', ['client' => $client, 'star' => $star->name]) }}
                        </p>
                    @elseif ($switchedOn && $star !== null)
                        <h3 class="flex items-center gap-1.5 text-sm/5 font-semibold text-zinc-950 dark:text-white">
                            <flux:icon.check-circle variant="micro" class="text-accent-content" />
                            {{ __('Switched on in :star', ['star' => $star->name]) }}
                        </h3>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $isPrompt
                                ? __(':star\'s clients can get it from their next request.', ['star' => $star->name])
                                : __(':star\'s clients can call it from their next request.', ['star' => $star->name]) }}
                        </p>
                    @elseif ($star === null)
                        <h3 class="text-sm/5 font-semibold text-zinc-950 dark:text-white">{{ __('Nexus refused this call') }}</h3>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Its Star has since been deleted, so there is nothing to switch on.') }}</p>
                    @elseif ($denialReason === App\Enums\DenialReason::NoName)
                        <h3 class="text-sm/5 font-semibold text-zinc-950 dark:text-white">{{ $isPrompt ? __('The request named no prompt') : __('The call named no tool') }}</h3>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __(':client sent it without a name, so there was nothing to run.', ['client' => $client]) }}</p>
                    @elseif ($denialReason === App\Enums\DenialReason::Unknown)
                        <h3 class="text-sm/5 font-semibold text-zinc-950 dark:text-white">
                            {{ $isPrompt ? __(':star has no prompt by this name', ['star' => $star->name]) : __(':star has no tool by this name', ['star' => $star->name]) }}
                        </h3>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $isPrompt
                                ? __('None of :star\'s Connections had a prompt called this when the request was made.', ['star' => $star->name])
                                : __('None of :star\'s Connections had a tool called this when the call was made.', ['star' => $star->name]) }}
                        </p>
                    @else
                        <h3 class="text-sm/5 font-semibold text-zinc-950 dark:text-white">{{ __('Nexus refused this call') }}</h3>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $isPrompt
                                ? __('The prompt is on in :star now, or :star no longer has it. Nexus also refuses a request whose arguments aren\'t an object.', ['star' => $star->name])
                                : __('The tool is on in :star now, or :star no longer has it. Nexus also refuses a call whose arguments aren\'t an object.', ['star' => $star->name]) }}
                        </p>
                    @endif
                @elseif ($connection === null)
                    <h3 class="text-sm/5 font-semibold text-zinc-950 dark:text-white">{{ $entry->status === App\Enums\ActivityStatus::NeedsAuth ? __('The server refused the sign-in') : $entry->status->label() }}</h3>
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Its Connection has since been deleted, so there is nothing to fix.') }}</p>
                @elseif ($entry->status === App\Enums\ActivityStatus::NeedsAuth)
                    <h3 class="text-sm/5 font-semibold text-zinc-950 dark:text-white">{{ __('Sign in to :connection again', ['connection' => $connection->name]) }}</h3>
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Its server refused Nexus\'s sign-in, so the call didn\'t reach it. Reconnect to sign in again.') }}</p>
                @elseif ($entry->status === App\Enums\ActivityStatus::Timeout)
                    <h3 class="text-sm/5 font-semibold text-zinc-950 dark:text-white">{{ __(':connection took too long to answer', ['connection' => $connection->name]) }}</h3>
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Nexus stopped waiting for its server. Open the Connection to check its status, or refresh its tools to try the server again.') }}</p>
                @else
                    <h3 class="text-sm/5 font-semibold text-zinc-950 dark:text-white">{{ __('The call to :connection failed', ['connection' => $connection->name]) }}</h3>
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('The server answered with an error, or couldn\'t be reached. Nexus doesn\'t keep what it said: open the Connection to see its status, or refresh its tools to try the server again.') }}</p>
                @endif
            </div>

            @php
                $offersSwitch = $entry->status === App\Enums\ActivityStatus::Denied && $switchedOff !== null && $star !== null;
                $offersList = $entry->status === App\Enums\ActivityStatus::Denied && $star !== null && $denialReason !== App\Enums\DenialReason::NoName;
                $offersReconnect = $entry->status === App\Enums\ActivityStatus::NeedsAuth && $connection !== null;
                $offersConnection = in_array($entry->status, [App\Enums\ActivityStatus::Timeout, App\Enums\ActivityStatus::Error], true) && $connection !== null;
            @endphp

            @if ($offersSwitch || $offersList || $offersReconnect || $offersConnection)
                <div class="flex flex-wrap items-center gap-2">
                    @if ($offersSwitch)
                        <flux:button variant="primary" size="sm" wire:click="switchOn">{{ __('Switch on in :star', ['star' => $star->name]) }}</flux:button>
                    @endif

                    @if ($offersList)
                        <flux:button size="sm" :href="route($listRoute, $star)" wire:navigate>{{ $listLabel }}</flux:button>
                    @endif

                    @if ($offersReconnect)
                        <flux:button variant="primary" size="sm" :href="route('connections.connect', $connection)">{{ __('Reconnect :connection', ['connection' => $connection->name]) }}</flux:button>
                    @endif

                    @if ($offersConnection)
                        <flux:button variant="primary" size="sm" :href="route('connections.show', $connection)" wire:navigate>{{ __('Open :connection', ['connection' => $connection->name]) }}</flux:button>
                        <flux:button size="sm" icon="arrow-path" wire:click="refreshTools">{{ __('Refresh tools') }}</flux:button>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <dl class="flex flex-col px-5 py-2 text-sm">
        <div class="flex gap-3 border-b border-zinc-200 py-2.25 dark:border-white/10">
            <dt class="w-27.5 shrink-0 text-zinc-600 dark:text-zinc-400">{{ __('Star') }}</dt>
            <dd class="min-w-0 break-words">
                @if ($star !== null)
                    <flux:link :href="route('stars.show', $star)" wire:navigate variant="ghost" class="font-medium text-accent-content!">{{ $star->name }}</flux:link>
                @else
                    <span class="text-zinc-500 italic dark:text-zinc-400">{{ __('Deleted Star') }}</span>
                @endif
            </dd>
        </div>

        <div class="flex gap-3 border-b border-zinc-200 py-2.25 dark:border-white/10">
            <dt class="w-27.5 shrink-0 text-zinc-600 dark:text-zinc-400">{{ __('Connection') }}</dt>
            <dd class="min-w-0 break-words">
                @if ($connection !== null)
                    <flux:link :href="route('connections.show', $connection)" wire:navigate variant="ghost" class="font-medium text-accent-content!">{{ $connection->name }}</flux:link>
                    @if (filled($connection->account_identity))
                        <span class="text-zinc-600 dark:text-zinc-400">· {{ $connection->account_identity }}</span>
                    @endif
                @elseif ($entry->connectionWasDeleted())
                    <span class="text-zinc-500 italic dark:text-zinc-400">{{ __('Deleted Connection') }}</span>
                @else
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('None') }}</span>
                @endif
            </dd>
        </div>

        <div class="flex gap-3 border-b border-zinc-200 py-2.25 dark:border-white/10">
            <dt class="w-27.5 shrink-0 text-zinc-600 dark:text-zinc-400">{{ __('Client') }}</dt>
            <dd class="min-w-0 break-words">
                @if ($entry->client_name !== null)
                    <span class="font-medium text-zinc-950 dark:text-white">{{ $entry->client_name }}</span>
                @else
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Not named') }}</span>
                @endif
            </dd>
        </div>

        <div class="flex gap-3 border-b border-zinc-200 py-2.25 dark:border-white/10">
            <dt class="w-27.5 shrink-0 text-zinc-600 dark:text-zinc-400">{{ __('Via') }}</dt>
            <dd class="min-w-0 font-medium text-zinc-950 dark:text-white">{{ $entry->via->label() }}</dd>
        </div>

        <div class="flex gap-3 border-b border-zinc-200 py-2.25 dark:border-white/10">
            <dt class="w-27.5 shrink-0 text-zinc-600 dark:text-zinc-400">{{ __('Kind') }}</dt>
            <dd class="min-w-0 font-medium text-zinc-950 dark:text-white">{{ $entry->kind->callLabel() }}</dd>
        </div>

        <div class="flex gap-3 border-b border-zinc-200 py-2.25 dark:border-white/10">
            <dt class="w-27.5 shrink-0 text-zinc-600 dark:text-zinc-400">{{ __('Server\'s name') }}</dt>
            <dd class="min-w-0 break-all">
                @if ($entry->downstream_name !== null)
                    <span class="font-mono text-zinc-950 dark:text-white">{{ $entry->downstream_name }}</span>
                @else
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('None') }}</span>
                @endif
            </dd>
        </div>

        <div class="flex gap-3 py-2.25">
            <dt class="w-27.5 shrink-0 text-zinc-600 dark:text-zinc-400">{{ __('Duration') }}</dt>
            <dd class="min-w-0 font-mono text-zinc-950 tabular-nums dark:text-white">{{ $entry->durationForHumans() }}</dd>
        </div>
    </dl>

    <p class="mt-auto flex items-center gap-2 border-t border-zinc-200 bg-zinc-50 px-5 py-3 text-xs text-zinc-600 dark:border-white/10 dark:bg-black/15 dark:text-zinc-400">
        <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0" />
        {{ __('Nexus never stores a call\'s arguments or results.') }}
    </p>
</section>
