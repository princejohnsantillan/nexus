{{--
    One numbered step of a how-to, as an item of an <ol>: its number in a
    circle, a heading with an optional aside on the right (such as a link),
    and the step's body as the slot. `pending` draws the number as an
    outline, for a step still to happen, such as checking a client works.
--}}
@props([
    'number',
    'heading',
    'pending' => false,
])

<li {{ $attributes->class('flex gap-3.5') }} data-step>
    <span @class([
        'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
        'bg-zinc-950 text-white dark:bg-white dark:text-zinc-950' => ! $pending,
        'border-[1.5px] border-zinc-950 text-zinc-950 dark:border-white dark:text-white' => $pending,
    ]) aria-hidden="true" data-step-number>{{ $number }}</span>

    <div class="min-w-0 flex-1">
        <div class="flex min-h-6 flex-wrap items-center justify-between gap-x-3 gap-y-1">
            <flux:heading level="3" class="font-semibold! wrap-anywhere" data-step-heading>{{ $heading }}</flux:heading>

            @isset($aside)
                <div class="text-sm">{{ $aside }}</div>
            @endisset
        </div>

        @if ($slot->hasActualContent())
            <div class="mt-2.5 space-y-2.5">
                {{ $slot }}
            </div>
        @endif
    </div>
</li>
