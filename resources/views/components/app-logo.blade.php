@props([
    'sidebar' => false,
])

@if ($sidebar)
    <flux:sidebar.brand name="{{ config('app.name') }}" {{ $attributes->class('px-1.5') }}>
        <x-slot name="logo" class="flex aspect-square size-7 items-center justify-center rounded-md bg-zinc-950 text-white dark:bg-white dark:text-zinc-950">
            <x-app-logo-icon class="size-4" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="{{ config('app.name') }}" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-7 items-center justify-center rounded-md bg-zinc-950 text-white dark:bg-white dark:text-zinc-950">
            <x-app-logo-icon class="size-4" />
        </x-slot>
    </flux:brand>
@endif
