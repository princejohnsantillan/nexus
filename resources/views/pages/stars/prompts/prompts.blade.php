<div class="mx-auto w-full max-w-5xl">
    <x-star-header :star="$star" current="prompts" />

    <div class="mt-8">
        @if ($this->groups === [])
            <x-empty-state icon="chat-bubble-left-right" :heading="__('No prompts yet')">
                {{ __('This Star doesn\'t include any Connections. Choose them on its overview.') }}

                <x-slot:actions>
                    <flux:button variant="primary" :href="route('stars.show', $star)" wire:navigate>{{ __('Choose connections') }}</flux:button>
                </x-slot:actions>
            </x-empty-state>
        @else
            <section aria-labelledby="prompts-heading">
                <flux:heading size="lg" level="2" id="prompts-heading">{{ __('Prompts') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Clients that support prompts, such as Claude Code, offer each one as handle__prompt. Every prompt is on unless you switch it off: it only gives the agent instructions, and the tools those lead to keep their own switches.') }}</flux:text>

                @foreach ($this->groups as $group)
                    <div wire:key="connection-{{ $group['connection']->id }}" class="mt-8">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <x-connector-logo :connector="$group['connection']->connector()" size="sm" />

                                <div class="min-w-0">
                                    <flux:heading size="lg" level="3" class="truncate">
                                        <flux:link :href="route('connections.prompts', $group['connection'])" variant="ghost" wire:navigate>{{ $group['connection']->name }}</flux:link>
                                    </flux:heading>
                                    <flux:text size="sm" class="mt-0.5">
                                        <span class="font-mono">{{ $group['connection']->handle }}</span>
                                        <x-account-label :connection="$group['connection']" separated />
                                        · {{ __(':enabled of :total on', ['enabled' => $group['enabled'], 'total' => count($group['prompts'])]) }}
                                    </flux:text>
                                </div>
                            </div>

                            @if ($group['prompts'] !== [])
                                <div class="flex flex-wrap gap-2">
                                    <flux:button size="sm" wire:click="switchConnection({{ $group['connection']->id }}, 'on')">{{ __('All on') }}</flux:button>
                                    <flux:button size="sm" wire:click="switchConnection({{ $group['connection']->id }}, 'off')">{{ __('All off') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="switchConnection({{ $group['connection']->id }}, 'reset')">{{ __('Reset all') }}</flux:button>
                                </div>
                            @endif
                        </div>

                        @if ($group['prompts'] === [])
                            <flux:text class="mt-3">
                                {{ __('Nexus has no prompts for this Connection. Its server may not have any.') }}
                                <flux:link :href="route('connections.show', $group['connection'])" wire:navigate>{{ __('Refresh its tools and prompts') }}</flux:link>
                            </flux:text>
                        @else
                            <flux:table class="mt-3">
                                <flux:table.columns>
                                    <flux:table.column class="w-0">{{ __('On') }}</flux:table.column>
                                    <flux:table.column>{{ __('Prompt') }}</flux:table.column>
                                    <flux:table.column>{{ __('Set by') }}</flux:table.column>
                                </flux:table.columns>

                                <flux:table.rows>
                                    @foreach ($group['prompts'] as $prompt)
                                        <flux:table.row :key="$prompt->prompt->id">
                                            <flux:table.cell>
                                                {{-- Keyed by its state, so a switch changed on the server is drawn afresh: Flux's switch keeps its own state otherwise. --}}
                                                <flux:switch
                                                    wire:key="switch-{{ $prompt->prompt->id }}-{{ $prompt->enabled ? 'on' : 'off' }}"
                                                    :checked="$prompt->enabled"
                                                    wire:click="switchPrompt({{ $prompt->prompt->id }}, {{ $prompt->enabled ? 'false' : 'true' }})"
                                                    :aria-label="__('Switch :name on or off', ['name' => $prompt->name])"
                                                />
                                            </flux:table.cell>
                                            <flux:table.cell class="max-w-md whitespace-normal!">
                                                <div class="break-all font-mono text-sm font-medium text-zinc-800 dark:text-white">{{ $prompt->name }}</div>
                                                @if ($prompt->prompt->title !== null)
                                                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $prompt->prompt->title }}</div>
                                                @endif
                                                @if ($prompt->prompt->description !== null)
                                                    <flux:text size="sm" class="mt-1 line-clamp-2">{{ $prompt->prompt->description }}</flux:text>
                                                @endif
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                @if ($prompt->followsDefault())
                                                    <flux:tooltip :content="__('Prompts are on unless you switch them off.')">
                                                        <flux:badge size="sm">{{ __('Default') }}</flux:badge>
                                                    </flux:tooltip>
                                                @else
                                                    <div class="flex items-center gap-1">
                                                        <flux:badge size="sm" color="blue">{{ __('Your choice') }}</flux:badge>
                                                        <flux:button size="xs" variant="ghost" wire:click="resetPrompt({{ $prompt->prompt->id }})" :aria-label="__('Reset :name to the default', ['name' => $prompt->name])">{{ __('Reset') }}</flux:button>
                                                    </div>
                                                @endif
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif
    </div>
</div>
