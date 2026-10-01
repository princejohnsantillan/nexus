{{--
    The top of every Star page: its name and description, and the row of
    links to its sub-pages. `current` names the page being shown.
--}}
@props([
    'star',
    'current',
])

<div>
    <flux:link :href="route('stars.index')" variant="subtle" class="text-sm" wire:navigate>&larr; {{ __('Stars') }}</flux:link>

    <flux:heading size="xl" level="1" class="mt-3 break-all">{{ $star->name }}</flux:heading>

    @if (filled($star->description))
        <flux:text class="mt-1">{{ $star->description }}</flux:text>
    @endif

    <flux:navbar class="-mb-px mt-4 border-b border-zinc-200 dark:border-zinc-700">
        <flux:navbar.item :href="route('stars.show', $star)" :current="$current === 'overview'" wire:navigate>{{ __('Overview') }}</flux:navbar.item>
        <flux:navbar.item :href="route('stars.tools', $star)" :current="$current === 'tools'" wire:navigate>{{ __('Tools') }}</flux:navbar.item>
    </flux:navbar>
</div>
