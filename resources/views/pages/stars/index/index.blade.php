<div class="mx-auto w-full max-w-5xl">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Stars') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Each Star is one MCP server endpoint that bundles some of your Connections.') }}</flux:text>
        </div>

        <div class="flex min-h-10 items-center gap-4">
            <span @class([
                'font-mono text-[13px] tabular-nums',
                'text-zinc-600 dark:text-zinc-400' => ! $this->isAtStarLimit,
                'text-warning' => $this->isAtStarLimit,
            ]) data-star-count>{{ trans_choice(':stars / :limit Star|:stars / :limit Stars', $this->starLimit, ['stars' => $this->stars->count(), 'limit' => $this->starLimit]) }}</span>

            {{-- With no Stars yet, the empty state offers the button instead. --}}
            @if ($this->stars->isNotEmpty())
                <flux:modal.trigger name="create-star">
                    <flux:button variant="primary" icon="plus" :disabled="$this->isAtStarLimit">{{ __('Create Star') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </div>

    @php($checklist = $this->gettingStarted)

    @if ($checklist !== null)
        @php($doneCount = $checklist->doneCount())
        @php($stepCount = count(App\Enums\GettingStartedStep::cases()))

        <section class="@container mt-8 rounded-xl border border-zinc-200 bg-white px-4 py-5 sm:px-6 dark:border-white/10 dark:bg-white/[4%]" aria-labelledby="getting-started-heading" data-getting-started>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
                <h2 id="getting-started-heading" class="text-base font-semibold text-zinc-950 dark:text-white">{{ __('Getting started') }}</h2>

                <p class="text-[13px] text-zinc-600 tabular-nums dark:text-zinc-400">{{ __(':done of :total done', ['done' => $doneCount, 'total' => $stepCount]) }}</p>

                <div class="order-last h-1 basis-full overflow-hidden rounded-full bg-zinc-200 sm:order-none sm:basis-auto sm:flex-1 dark:bg-white/10" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $stepCount }}" aria-valuenow="{{ $doneCount }}" aria-labelledby="getting-started-heading">
                    <div @class([
                        'h-full rounded-full bg-accent',
                        'w-0' => $doneCount === 0,
                        'w-1/4' => $doneCount === 1,
                        'w-1/2' => $doneCount === 2,
                        'w-3/4' => $doneCount === 3,
                    ])></div>
                </div>

                <flux:button variant="ghost" size="sm" icon="x-mark" square class="-my-1 -me-2 ms-auto sm:ms-0" wire:click="dismissGettingStarted" :aria-label="__('Dismiss getting started')" />
            </div>

            <ol class="mt-4 grid gap-3 @xl:grid-cols-2 @4xl:grid-cols-4">
                @foreach (App\Enums\GettingStartedStep::cases() as $step)
                    @php($isDone = $checklist->isDone($step))
                    @php($isNext = $checklist->nextStep() === $step)

                    <li data-step="{{ $step->value }}" aria-current="{{ $isNext ? 'step' : 'false' }}" @class([
                        'flex min-w-0 gap-2.5 rounded-lg border px-4 py-3.5',
                        'border-transparent bg-zinc-50 dark:bg-white/5' => ! $isNext,
                        'border-accent/30 bg-accent-wash' => $isNext,
                    ]) wire:key="getting-started-{{ $step->value }}">
                        @if ($isDone)
                            <flux:icon.check-circle variant="mini" class="mt-0.5 size-4 shrink-0 text-success" />
                        @elseif ($isNext)
                            <span class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border-[1.5px] border-accent" aria-hidden="true"><span class="size-1.5 rounded-full bg-accent"></span></span>
                        @else
                            <span class="mt-0.5 size-4 shrink-0 rounded-full border-[1.5px] border-zinc-300 dark:border-white/25" aria-hidden="true"></span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <h3 @class([
                                'text-sm',
                                'font-medium text-zinc-950 dark:text-white' => ! $isNext,
                                'font-semibold text-zinc-950 dark:text-white' => $isNext,
                            ])>
                                {{ $step->label() }}
                                <span class="sr-only">{{ $isDone ? __('(done)') : __('(to do)') }}</span>
                            </h3>

                            <div class="mt-1 flex min-w-0 flex-wrap items-baseline gap-x-2 text-[13px] text-zinc-600 dark:text-zinc-400">
                                @if ($isDone)
                                    <p class="min-w-0 truncate" title="{{ $checklist->doneWith($step) }}">{{ $checklist->doneWith($step) }}</p>
                                @else
                                    @switch($step)
                                        @case(App\Enums\GettingStartedStep::ConnectServer)
                                            <a href="{{ route('connections.index') }}#add-more" class="shrink-0 font-medium text-accent-content hover:underline" wire:navigate>{{ __('Add connection') }} <span aria-hidden="true">&rarr;</span></a>
                                            @break

                                        @case(App\Enums\GettingStartedStep::CreateStar)
                                            <flux:modal.trigger name="create-star">
                                                <button type="button" class="shrink-0 cursor-pointer font-medium text-accent-content hover:underline">{{ __('Create Star') }} <span aria-hidden="true">&rarr;</span></button>
                                            </flux:modal.trigger>
                                            @break

                                        @case(App\Enums\GettingStartedStep::SetUpClient)
                                            @if ($checklist->star !== null)
                                                <p class="min-w-0 truncate" title="{{ __('Add :star to a client', ['star' => $checklist->star->name]) }}">{{ __('Add :star to a client', ['star' => $checklist->star->name]) }}</p>
                                                <a href="{{ route('stars.show', $checklist->star) }}#setup" class="shrink-0 font-medium text-accent-content hover:underline" wire:navigate>{{ __('Open setup') }} <span aria-hidden="true">&rarr;</span></a>
                                            @else
                                                <p class="min-w-0 truncate">{{ __('Once you have a Star') }}</p>
                                            @endif
                                            @break

                                        @case(App\Enums\GettingStartedStep::ReceiveFirstCall)
                                            @if ($checklist->star !== null)
                                                @php($waiting = $checklist->client !== null ? __('Listening on :star…', ['star' => $checklist->star->name]) : __('No calls yet'))

                                                <p class="min-w-0 truncate" title="{{ $waiting }}">{{ $waiting }}</p>
                                                <a href="{{ route('stars.show', $checklist->star) }}#setup" class="shrink-0 font-medium text-accent-content hover:underline" wire:navigate>{{ __('Open setup') }} <span aria-hidden="true">&rarr;</span></a>
                                            @else
                                                <p class="min-w-0 truncate">{{ __('No calls yet') }}</p>
                                            @endif
                                            @break
                                    @endswitch
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @if ($this->isAtStarLimit)
        <flux:callout icon="exclamation-triangle" color="amber" class="mt-8" :heading="__('Star limit reached')">
            <flux:callout.text>{{ $this->limitMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->stars->isEmpty())
        <x-empty-state icon="star" :heading="__('No Stars yet')" class="mt-8">
            {{ __('Create a Star, choose the Connections it includes and switch each tool on or off. Then add it once to Claude Code, claude.ai, Codex, Cursor or Grok.') }}

            <x-slot:actions>
                <flux:modal.trigger name="create-star">
                    <flux:button variant="primary" icon="plus">{{ __('Create your first Star') }}</flux:button>
                </flux:modal.trigger>
            </x-slot:actions>
        </x-empty-state>
    @else
        <div class="@container mt-8">
            <div class="grid gap-4 @xl:grid-cols-2 @4xl:grid-cols-3">
                @foreach ($this->stars as $star)
                    @php($calls = $this->calls[$star->id])

                    <article class="relative flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white hover:border-zinc-300 dark:border-white/10 dark:bg-white/[4%] dark:hover:border-white/20" aria-labelledby="star-{{ $star->id }}-name" wire:key="star-{{ $star->id }}" data-star-card>
                        <div class="flex flex-1 flex-col gap-4 p-5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h2 id="star-{{ $star->id }}-name" class="truncate text-base font-semibold text-zinc-950 dark:text-white">
                                        {{-- The link covers the card, so a click anywhere on it opens the Star. --}}
                                        <a href="{{ route('stars.show', $star) }}" class="after:absolute after:inset-0" wire:navigate>{{ $star->name }}</a>
                                    </h2>

                                    @if (filled($star->description))
                                        <p class="mt-1 line-clamp-2 text-[13px] text-zinc-600 dark:text-zinc-400">{{ $star->description }}</p>
                                    @endif
                                </div>

                                <flux:dropdown align="end" class="relative z-10 -me-2 -mt-1.5" x-data>
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" square :aria-label="__('More actions for :name', ['name' => $star->name])" />

                                    <flux:menu>
                                        <flux:menu.item
                                            icon="document-duplicate"
                                            data-url="{{ $star->clientUrl() }}"
                                            data-copied="{{ __('Copied the endpoint URL of :name.', ['name' => $star->name]) }}"
                                            x-on:click="navigator.clipboard.writeText($el.dataset.url).then(() => $flux.toast({ text: $el.dataset.copied, variant: 'success' }))"
                                        >{{ __('Copy endpoint URL') }}</flux:menu.item>
                                        <flux:menu.item icon="pulse" :href="route('activity.index', ['star' => $star->public_id])" wire:navigate>{{ __('View activity') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>

                            @if ($star->connections->isEmpty())
                                <p class="text-[13px] text-zinc-500 dark:text-zinc-400">{{ __('No Connections yet') }}</p>
                            @else
                                <div class="flex min-w-0 items-center gap-1.5">
                                    @foreach ($star->connections->take(3) as $connection)
                                        <x-connector-logo :connector="$connection->connector()" size="sm" wire:key="star-{{ $star->id }}-logo-{{ $connection->id }}" />
                                    @endforeach

                                    @if ($star->connections->count() > 3)
                                        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">+{{ $star->connections->count() - 3 }}</span>
                                    @endif

                                    <p class="min-w-0 truncate ps-1 text-[13px] text-zinc-600 dark:text-zinc-400">{{ $star->connections->pluck('name')->join(' · ') }}</p>
                                </div>
                            @endif

                            <div class="mt-auto flex items-center justify-between gap-2">
                                <p class="text-[13px] font-medium text-zinc-950 dark:text-white">{{ __(':enabled of :total tools on', $this->toolCounts[$star->id]) }}</p>

                                <span class="inline-flex h-5.5 shrink-0 items-center rounded-md border border-zinc-300 px-1.75 text-xs font-medium text-zinc-950 dark:border-white/20 dark:text-white">{{ $star->access_mode->label() }}</span>
                            </div>
                        </div>

                        {{-- A Connection that needs attention matters more than the last call, so it takes the footer. --}}
                        @if (isset($this->problems[$star->id]))
                            @php($problems = $this->problems[$star->id])
                            @php($problem = $problems[0])

                            <div @class([
                                'flex min-h-11 items-center gap-2 border-t py-2 ps-5 pe-3',
                                'border-warning-rule bg-warning-wash text-warning' => $problem->tone() === 'warning',
                                'border-danger-rule bg-danger-wash text-danger' => $problem->tone() === 'danger',
                            ]) data-star-card-footer data-star-card-problem="{{ $problem->connection->handle }}">
                                <span class="size-1.75 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
                                <p class="min-w-0 flex-1 truncate text-[13px] font-medium">
                                    {{ $problem->headline() }}

                                    @if (count($problems) > 1)
                                        <span class="font-normal">{{ __('and :count more', ['count' => count($problems) - 1]) }}</span>
                                    @endif
                                </p>

                                <flux:button size="sm" :href="$problem->fixUrl()" class="relative z-10 shrink-0" :aria-label="$problem->fixLabel(withName: true)">{{ $problem->fixLabel() }}</flux:button>
                            </div>
                        @else
                            <div class="flex min-h-11 items-center gap-2 border-t border-zinc-200 px-5 py-2.5 dark:border-white/10" data-star-card-footer>
                                @if ($calls->lastCalledAt !== null)
                                    <span class="size-1.75 shrink-0 rounded-full bg-success" aria-hidden="true"></span>
                                    <p class="min-w-0 flex-1 truncate text-[13px] text-zinc-600 dark:text-zinc-400">
                                        {{ __('Last call') }} <time datetime="{{ $calls->lastCalledAt->toIso8601String() }}" title="{{ $calls->lastCalledAt->toDayDateTimeString() }}">{{ $calls->lastCalledAt->diffForHumans() }}</time>
                                    </p>

                                    @if ($calls->recentTotal() > 0)
                                        <x-sparkline :values="$calls->daily" />
                                        <span class="sr-only">{{ trans_choice(':count call in the last :days days|:count calls in the last :days days', $calls->recentTotal(), ['days' => count($calls->daily)]) }}</span>
                                    @endif
                                @else
                                    <span class="size-1.75 shrink-0 rounded-full bg-accent" aria-hidden="true"></span>
                                    <p class="min-w-0 flex-1 truncate text-[13px] text-zinc-600 dark:text-zinc-400">{{ __('Waiting for its first call…') }}</p>
                                @endif
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        </div>
    @endif

    <flux:modal name="create-star" class="w-full max-w-xl">
        <form wire:submit="create" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Create a Star') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Its read-only tools start on and everything else off. You can change any of that on its Tools page.') }}</flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Name')" :description="__('What you call this Star, e.g. after the client or the job it is for.')" placeholder="Work" maxlength="100" />

            <flux:textarea wire:model="description" :label="__('Description')" :badge="__('Optional')" :description="__('Agents see this, so say what the Star is for.')" rows="2" maxlength="500" />

            <flux:radio.group wire:model="accessMode" variant="cards" class="flex-col" :label="__('Access mode')" :description="__('How clients authenticate to the Star. You can change it later on its Access page.')">
                @foreach (App\Enums\StarAccessMode::cases() as $option)
                    <flux:radio :value="$option->value" :label="$option->label()" :description="$option->description()" wire:key="access-mode-{{ $option->value }}" />
                @endforeach
            </flux:radio.group>

            @if ($this->connections->isEmpty())
                <flux:callout icon="link" :heading="__('No Connections yet')">
                    <flux:callout.text>{{ __('You can create the Star now and add Connections to it later.') }}</flux:callout.text>

                    <x-slot name="actions">
                        <flux:button size="sm" :href="route('connections.index').'#add-more'" wire:navigate>{{ __('Add connection') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @else
                <x-connection-picker :connections="$this->connections" wire:model="connectionIds" :label="__('Connections')" :description="__('The Star includes the tools of the Connections you choose.')" />
            @endif

            <flux:error name="limit" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">{{ __('Create Star') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
