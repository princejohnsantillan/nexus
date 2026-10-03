{{--
    The top of every Connection page: a breadcrumb back to Connections, its
    logo, name, status, handle and account label, and the row of links to
    its sub-pages. `current` names the page being shown.
--}}
@props([
    'connection',
    'current',
])

<div>
    <nav aria-label="{{ __('Breadcrumb') }}" class="flex min-w-0 items-center gap-1.5 text-sm">
        <flux:link :href="route('connections.index')" variant="subtle" wire:navigate>{{ __('Connections') }}</flux:link>
        <span aria-hidden="true" class="text-zinc-300 dark:text-zinc-600">/</span>
        <span aria-current="page" class="truncate text-zinc-950 dark:text-white">{{ $connection->name }}</span>
    </nav>

    <div class="mt-4 flex items-start gap-4">
        <x-connector-logo :connector="$connection->connector()" class="mt-0.5" />

        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <flux:heading size="xl" level="1" class="break-all text-[2rem]! leading-tight! font-semibold! tracking-tight">{{ $connection->name }}</flux:heading>
                <x-connection-status :status="$connection->status" />
            </div>

            <flux:text class="mt-1">
                <span class="font-mono">{{ $connection->handle }}</span>
                <x-account-label :connection="$connection" separated />
            </flux:text>
        </div>
    </div>

    <flux:navbar class="-mb-px mt-5 border-b border-zinc-200 dark:border-white/10">
        <flux:navbar.item :href="route('connections.show', $connection)" :current="$current === 'overview'" wire:navigate>{{ __('Overview') }}</flux:navbar.item>
        <flux:navbar.item :href="route('connections.tools', $connection)" :current="$current === 'tools'" wire:navigate>{{ __('Tools') }}</flux:navbar.item>
        <flux:navbar.item :href="route('connections.prompts', $connection)" :current="$current === 'prompts'" wire:navigate>{{ __('Prompts') }}</flux:navbar.item>
    </flux:navbar>
</div>
