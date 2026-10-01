{{--
    A service's logo on a tile: the connector's official logo, inlined so the
    monochrome marks (GitHub, Linear) take the text colour and read in dark
    mode, or a server icon for a custom server (no connector). `size` is `sm`
    for lists and pickers, `md` for cards and headers.
--}}
@props([
    'connector' => null,
    'size' => 'md',
])

<div {{ $attributes->class([
    'flex shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-white',
    'size-8' => $size === 'sm',
    'size-10' => $size !== 'sm',
]) }} aria-hidden="true">
    @if ($connector !== null)
        {{ $connector->logo($size === 'sm' ? 'size-4' : 'size-6') }}
    @else
        <flux:icon.server-stack :class="($size === 'sm' ? 'size-4' : 'size-5').' text-zinc-500 dark:text-zinc-300'" />
    @endif
</div>
