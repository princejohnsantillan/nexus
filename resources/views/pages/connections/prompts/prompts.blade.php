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

            <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-white/[4%]">
                <flux:table class="[&_td]:border-zinc-200! [&_td]:px-5! dark:[&_td]:border-white/10! [&_th]:border-zinc-200! [&_th]:bg-zinc-50 [&_th]:px-5! [&_th]:text-xs! [&_th]:text-zinc-600! dark:[&_th]:border-white/10! dark:[&_th]:bg-black/15 dark:[&_th]:text-zinc-300!">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Prompt') }}</flux:table.column>
                        <flux:table.column>{{ __('Arguments') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->prompts as $prompt)
                            <flux:table.row :key="$prompt->id">
                                <flux:table.cell class="max-w-md min-w-64 whitespace-normal! align-top">
                                    @if ($prompt->title !== null)
                                        <div class="font-medium text-zinc-950 dark:text-white">{{ $prompt->title }}</div>
                                        <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $prompt->name }}</div>
                                    @else
                                        <div class="font-mono font-medium text-zinc-950 dark:text-white">{{ $prompt->name }}</div>
                                    @endif
                                    @if ($prompt->description !== null)
                                        <p class="mt-1 line-clamp-3 text-sm text-zinc-500 dark:text-zinc-400">{{ $prompt->description }}</p>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="max-w-md min-w-56 whitespace-normal! align-top">
                                    @forelse ($prompt->arguments() as $argument)
                                        <div @class(['mt-2' => ! $loop->first])>
                                            <span class="font-mono text-sm text-zinc-950 dark:text-white">{{ $argument['name'] }}</span>
                                            <span @class([
                                                'ms-1 inline-flex h-5 items-center rounded-md px-1.5 text-xs font-medium whitespace-nowrap',
                                                'bg-warning-wash text-warning' => $argument['required'],
                                                'bg-zinc-50 text-zinc-600 ring-1 ring-zinc-200 ring-inset dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10' => ! $argument['required'],
                                            ])>{{ $argument['required'] ? __('Required') : __('Optional') }}</span>
                                            @if ($argument['title'] !== null || $argument['description'] !== null)
                                                <p class="mt-0.5 line-clamp-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $argument['description'] ?? $argument['title'] }}</p>
                                            @endif
                                        </div>
                                    @empty
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('None') }}</span>
                                    @endforelse
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        @endif
    </div>
</div>
