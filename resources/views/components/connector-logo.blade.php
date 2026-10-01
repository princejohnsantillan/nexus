{{--
    A service's logo: the connector's official logo, inlined so the
    monochrome marks (GitHub, Linear) take the text colour and read in dark
    mode, or a server icon for a custom server (no connector). `size` is `md`
    on a tile for cards and headers, `sm` on a tile for lists and pickers, and
    `xs` with no tile, in the text colour, for badges and running text.
--}}
@props([
    'connector' => null,
    'size' => 'md',
])

@php
    [$tile, $logo, $icon] = match ($size) {
        'xs' => ['size-3.5', 'size-3.5', 'size-3.5'],
        'sm' => ['size-8 rounded-lg bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-white', 'size-4', 'size-4 text-zinc-500 dark:text-zinc-300'],
        default => ['size-10 rounded-lg bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-white', 'size-6', 'size-5 text-zinc-500 dark:text-zinc-300'],
    };
@endphp

<div {{ $attributes->class(['flex shrink-0 items-center justify-center', $tile]) }} aria-hidden="true">
    @if ($connector !== null)
        {{ $connector->logo($logo) }}
    @else
        <flux:icon.server-stack :class="$icon" />
    @endif
</div>
