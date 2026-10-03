{{--
    One tool or prompt as a permission: its exposed name in monospace (the
    `name` slot), its title, its description cut to two lines and any
    `hints` badges, with the switch and who set it (the slot) on the right.
    Rows sit in a bordered list, divided by hairlines.
--}}
@props([
    'title' => null,
    'description' => null,
])

<div {{ $attributes->class('flex items-start gap-4 px-4 py-3.5') }}>
    <div class="min-w-0 flex-1">
        <div class="font-mono text-sm font-medium break-all text-zinc-950 dark:text-white">{{ $name }}</div>

        @if (filled($title))
            <div class="mt-0.5 text-sm text-zinc-600 dark:text-zinc-300">{{ $title }}</div>
        @endif

        @if (filled($description))
            <flux:text class="mt-1 line-clamp-2">{{ $description }}</flux:text>
        @endif

        @if (isset($hints) && $hints->hasActualContent())
            <div class="mt-2">{{ $hints }}</div>
        @endif
    </div>

    <div class="flex w-24 shrink-0 flex-col items-end gap-1.5 text-end sm:w-32">
        {{ $slot }}
    </div>
</div>
