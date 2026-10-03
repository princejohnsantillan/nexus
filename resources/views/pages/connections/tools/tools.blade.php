<div class="mx-auto w-full max-w-5xl">
    <x-connection-header :connection="$connection" current="tools" />

    <div class="mt-8">
        @if ($this->tools->isEmpty())
            <x-empty-state icon="wrench-screwdriver" :heading="__('No tools')">
                @if ($connection->catalog_refreshed_at === null)
                    {{ __('Nexus hasn\'t loaded this Connection\'s tools yet. Refresh its tools from its overview.') }}
                @else
                    {{ __('The server listed no tools at the last refresh.') }}
                @endif

                <x-slot:actions>
                    <flux:button :href="route('connections.show', $connection)" wire:navigate>{{ __('Go to the overview') }}</flux:button>
                </x-slot:actions>
            </x-empty-state>
        @else
            <flux:text>{{ __('The tools the server listed at the last refresh, with the behaviour hints it declared for each. Choose a tool for its parameters, the Stars that have it on and its recent calls.') }}</flux:text>

            <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-white/[4%]">
                <flux:table class="[&_td]:border-zinc-200! [&_td]:px-5! dark:[&_td]:border-white/10! [&_th]:border-zinc-200! [&_th]:bg-zinc-50 [&_th]:px-5! [&_th]:text-xs! [&_th]:text-zinc-600! dark:[&_th]:border-white/10! dark:[&_th]:bg-black/15 dark:[&_th]:text-zinc-300!">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Tool') }}</flux:table.column>
                        <flux:table.column>{{ __('Read-only') }}</flux:table.column>
                        <flux:table.column>{{ __('Destructive') }}</flux:table.column>
                        <flux:table.column>{{ __('Idempotent') }}</flux:table.column>
                        <flux:table.column>{{ __('Open-world') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->tools as $tool)
                            <flux:table.row :key="$tool->id">
                                <flux:table.cell class="max-w-md min-w-64 whitespace-normal!">
                                    <x-tool-flyout.trigger :tool="$tool" class="block">
                                        @if ($tool->title !== null)
                                            <span class="block font-medium text-zinc-950 dark:text-white">{{ $tool->title }}</span>
                                            <span class="block font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $tool->name }}</span>
                                        @else
                                            <span class="block font-mono font-medium text-zinc-950 dark:text-white">{{ $tool->name }}</span>
                                        @endif
                                    </x-tool-flyout.trigger>
                                    @if ($tool->description !== null)
                                        <p class="mt-1 line-clamp-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $tool->description }}</p>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell><x-tool-hint :value="$tool->read_only" tone="success" /></flux:table.cell>
                                <flux:table.cell><x-tool-hint :value="$tool->destructive" tone="danger" /></flux:table.cell>
                                <flux:table.cell><x-tool-hint :value="$tool->idempotent" /></flux:table.cell>
                                <flux:table.cell><x-tool-hint :value="$tool->open_world" tone="warning" /></flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </div>

    <x-tool-flyout :details="$this->toolDetails" />
</div>
