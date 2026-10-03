{{--
    The top of an app page, as on board A: the title (32px bold, tight, on
    38px), its description under it, and an optional aside (such as a count
    and the page's main button) on the right, level with the bottom of the
    text. On a phone the aside drops under the text. Give the aside its own
    gap, as each board spaces its pieces differently.
--}}
@props([
    'heading',
    'description' => null,
])

<div {{ $attributes->class('flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between sm:gap-6') }}>
    <div class="flex min-w-0 flex-col gap-1.5">
        <flux:heading level="1" class="text-[2rem]! leading-[2.375rem]! font-bold! tracking-tight text-zinc-950! dark:text-white!">{{ $heading }}</flux:heading>

        @if (filled($description))
            <p class="text-sm leading-[1.375rem] text-zinc-600 dark:text-zinc-400">{{ $description }}</p>
        @endif
    </div>

    @isset($aside)
        <div {{ $aside->attributes->class('flex shrink-0 items-center') }}>
            {{ $aside }}
        </div>
    @endisset
</div>
