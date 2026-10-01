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
            <flux:text>{{ __('The tools the server listed at the last refresh, with the behaviour hints it declared for each.') }}</flux:text>

            <flux:table class="mt-4">
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
                            <flux:table.cell class="max-w-md whitespace-normal!">
                                <div class="font-medium text-zinc-800 dark:text-white">{{ $tool->title ?? $tool->name }}</div>
                                @if ($tool->title !== null)
                                    <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $tool->name }}</div>
                                @endif
                                @if ($tool->description !== null)
                                    <flux:text size="sm" class="mt-1 line-clamp-3">{{ $tool->description }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell><x-tool-hint :value="$tool->read_only" /></flux:table.cell>
                            <flux:table.cell><x-tool-hint :value="$tool->destructive" /></flux:table.cell>
                            <flux:table.cell><x-tool-hint :value="$tool->idempotent" /></flux:table.cell>
                            <flux:table.cell><x-tool-hint :value="$tool->open_world" /></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</div>
