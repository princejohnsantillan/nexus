<div class="mx-auto w-full max-w-5xl">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Connections') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Each Connection is one of your accounts on a remote MCP server.') }}</flux:text>
        </div>

        @if ($this->connections->isNotEmpty())
            <flux:button variant="primary" icon="plus" :href="route('connections.add')" wire:navigate>{{ __('Add connection') }}</flux:button>
        @endif
    </div>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->connections->isEmpty())
        <x-empty-state icon="link" :heading="__('No Connections yet')">
            {{ __('Connect GitHub, Notion, Linear or any remote MCP server once, then use it in as many Stars as you like.') }}

            <x-slot:actions>
                <flux:button variant="primary" icon="plus" :href="route('connections.add')" wire:navigate>{{ __('Add your first connection') }}</flux:button>
            </x-slot:actions>
        </x-empty-state>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Handle') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Tools') }}</flux:table.column>
                <flux:table.column>{{ __('Last refreshed') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->connections as $connection)
                    <flux:table.row :key="$connection->id">
                        <flux:table.cell>
                            <flux:link :href="route('connections.show', $connection)" wire:navigate class="font-medium">{{ $connection->name }}</flux:link>

                            @if (filled($connection->description))
                                <flux:text size="sm" class="mt-0.5 max-w-xs truncate">{{ __('Use for: :description', ['description' => $connection->description]) }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="font-mono">{{ $connection->handle }}</flux:table.cell>
                        <flux:table.cell><x-connection-status :status="$connection->status" /></flux:table.cell>
                        <flux:table.cell align="end">{{ $connection->tools_count }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($connection->catalog_refreshed_at === null)
                                {{ __('Never') }}
                            @else
                                <time datetime="{{ $connection->catalog_refreshed_at->toIso8601String() }}" title="{{ $connection->catalog_refreshed_at->toDayDateTimeString() }}">{{ $connection->catalog_refreshed_at->diffForHumans() }}</time>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
