@props([
    'icon',
    'heading',
])

<div {{ $attributes->class('flex flex-col items-center rounded-xl border border-dashed border-zinc-300 px-6 py-12 text-center dark:border-zinc-600') }}>
    <div class="flex size-12 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-700">
        <flux:icon :icon="$icon" class="size-6 text-zinc-500 dark:text-zinc-300" />
    </div>

    <flux:heading size="lg" level="2" class="mt-4">{{ $heading }}</flux:heading>

    <flux:text class="mt-2 max-w-md">{{ $slot }}</flux:text>

    @isset($actions)
        <div class="mt-6 flex flex-wrap justify-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
