<div class="mx-auto w-full max-w-5xl">
    <flux:link :href="route('connections.index')" variant="subtle" class="text-sm" wire:navigate>&larr; {{ __('Connections') }}</flux:link>

    <flux:heading size="xl" level="1" class="mt-3">{{ __('Add connection') }}</flux:heading>
    <flux:text class="mt-2">{{ __('Choose the MCP server to connect. Nexus signs in to it once, and every Star you add it to can use its tools.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->limitMessage !== null)
        <flux:callout icon="exclamation-triangle" color="amber" class="mb-6" :heading="__('Connection limit reached')">
            <flux:callout.text>{{ $this->limitMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <flux:card class="flex flex-col">
            <div class="flex size-10 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-700">
                <flux:icon.server-stack class="size-5 text-zinc-500 dark:text-zinc-300" />
            </div>

            <flux:heading size="lg" level="2" class="mt-4">{{ __('Custom MCP server') }}</flux:heading>
            <flux:text class="mt-1 flex-1">{{ __('Any remote MCP server, by URL. It can need no sign-in, or a header such as an API key.') }}</flux:text>

            <div class="mt-4">
                @if ($this->limitMessage === null)
                    <flux:button :href="route('connections.add-custom')" wire:navigate>{{ __('Connect') }}</flux:button>
                @else
                    <flux:button disabled>{{ __('Connect') }}</flux:button>
                @endif
            </div>
        </flux:card>
    </div>
</div>
