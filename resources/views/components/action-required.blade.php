{{--
    The sidebar's "Action required" card: the first of the user's
    Connections that needs attention, how many tools in which Stars it
    makes unavailable, and its fix. Pass the problems from
    App\Stars\ConnectionProblems::forUser(); with none, it shows nothing.
--}}
@props([
    'problems',
])

@if ($problems !== [])
    @php($problem = $problems[0])

    <section {{ $attributes->class('flex flex-col gap-2.5 rounded-lg border border-warning-rule bg-white p-3.5 dark:bg-white/[4%]') }} aria-labelledby="action-required-heading" data-action-required>
        <div class="flex items-center gap-2">
            <flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0 text-warning" />
            <h2 id="action-required-heading" class="text-[13px] font-semibold text-zinc-950 dark:text-white">{{ __('Action required') }}</h2>
        </div>

        <p class="text-[13px] leading-5 text-zinc-600 dark:text-zinc-400">{{ $problem->summary() }} {{ $problem->impact() }}</p>

        @if (count($problems) > 1)
            <a href="{{ route('connections.index') }}" class="-mt-1 text-[13px] font-medium text-accent-content hover:underline" wire:navigate>{{ trans_choice(':count more Connection needs attention|:count more Connections need attention', count($problems) - 1) }}</a>
        @endif

        <flux:button size="sm" class="w-full" :href="$problem->fixUrl()" data-action-required-fix>{{ $problem->fixLabel(withName: true) }}</flux:button>
    </section>
@endif
