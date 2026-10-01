{{--
    The top of every Connection page: its logo, name, status, handle and
    account label, and the row of links to its sub-pages. `current` names
    the page being shown.
--}}
@props([
    'connection',
    'current',
])

<div>
    <flux:link :href="route('connections.index')" variant="subtle" class="text-sm" wire:navigate>&larr; {{ __('Connections') }}</flux:link>

    <div class="mt-3 flex flex-wrap items-center gap-3">
        <x-connector-logo :connector="$connection->connector()" />
        <flux:heading size="xl" level="1" class="break-all">{{ $connection->name }}</flux:heading>
        <x-connection-status :status="$connection->status" />
    </div>

    <flux:text class="mt-1">
        <span class="font-mono">{{ $connection->handle }}</span>
        <x-account-label :connection="$connection" separated />
    </flux:text>

    <flux:navbar class="-mb-px mt-4 border-b border-zinc-200 dark:border-zinc-700">
        <flux:navbar.item :href="route('connections.show', $connection)" :current="$current === 'overview'" wire:navigate>{{ __('Overview') }}</flux:navbar.item>
        <flux:navbar.item :href="route('connections.tools', $connection)" :current="$current === 'tools'" wire:navigate>{{ __('Tools') }}</flux:navbar.item>
    </flux:navbar>
</div>
