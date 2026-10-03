{{--
    What a list says when it has nothing to show. compact makes it smaller,
    for a list inside a card or flyout, with its heading one level down.
--}}
@props([
    'icon',
    'heading',
    'compact' => false,
])

<div {{ $attributes->class([
    'flex flex-col items-center rounded-xl border border-dashed border-zinc-300 text-center dark:border-zinc-600',
    'px-6 py-12' => ! $compact,
    'px-4 py-6' => $compact,
]) }}>
    <div @class([
        'flex items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-700',
        'size-12' => ! $compact,
        'size-9' => $compact,
    ])>
        <flux:icon :icon="$icon" :class="($compact ? 'size-5' : 'size-6').' text-zinc-500 dark:text-zinc-300'" />
    </div>

    <flux:heading :size="$compact ? 'base' : 'lg'" :level="$compact ? 3 : 2" :class="$compact ? 'mt-3' : 'mt-4'">{{ $heading }}</flux:heading>

    <flux:text :class="'max-w-md '.($compact ? 'mt-1' : 'mt-2')">{{ $slot }}</flux:text>

    @isset($actions)
        <div @class(['flex flex-wrap justify-center gap-2', 'mt-6' => ! $compact, 'mt-4' => $compact])>
            {{ $actions }}
        </div>
    @endisset
</div>
