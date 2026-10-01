<div class="mx-auto w-full max-w-5xl">
    <x-connection-header :connection="$connection" current="prompts" />

    <div class="mt-8">
        @if ($this->prompts->isEmpty())
            <x-empty-state icon="chat-bubble-left-right" :heading="__('No prompts')">
                @if ($connection->catalog_refreshed_at === null)
                    {{ __('Nexus hasn\'t loaded this Connection\'s catalog yet. Refresh its tools from its overview, which loads its prompts too.') }}
                @else
                    {{ __('The server listed no prompts at the last refresh. Not every server has them.') }}
                @endif

                <x-slot:actions>
                    <flux:button :href="route('connections.show', $connection)" wire:navigate>{{ __('Go to the overview') }}</flux:button>
                </x-slot:actions>
            </x-empty-state>
        @else
            <flux:text>{{ __('The prompts the server listed at the last refresh. Clients that support prompts, such as Claude Code, offer them as slash commands.') }}</flux:text>

            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>{{ __('Prompt') }}</flux:table.column>
                    <flux:table.column>{{ __('Arguments') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->prompts as $prompt)
                        <flux:table.row :key="$prompt->id">
                            <flux:table.cell class="max-w-md whitespace-normal!">
                                <div class="font-medium text-zinc-800 dark:text-white">{{ $prompt->title ?? $prompt->name }}</div>
                                @if ($prompt->title !== null)
                                    <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $prompt->name }}</div>
                                @endif
                                @if ($prompt->description !== null)
                                    <flux:text size="sm" class="mt-1 line-clamp-3">{{ $prompt->description }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="max-w-md whitespace-normal!">
                                @forelse ($prompt->arguments() as $argument)
                                    <div @class(['mt-2' => ! $loop->first])>
                                        <span class="font-mono text-sm text-zinc-800 dark:text-white">{{ $argument['name'] }}</span>
                                        @if ($argument['required'])
                                            <flux:badge size="sm" color="amber" class="ms-1">{{ __('Required') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" class="ms-1">{{ __('Optional') }}</flux:badge>
                                        @endif
                                        @if ($argument['title'] !== null || $argument['description'] !== null)
                                            <flux:text size="sm" class="mt-0.5 line-clamp-2">{{ $argument['description'] ?? $argument['title'] }}</flux:text>
                                        @endif
                                    </div>
                                @empty
                                    <flux:text size="sm">{{ __('None') }}</flux:text>
                                @endforelse
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</div>
