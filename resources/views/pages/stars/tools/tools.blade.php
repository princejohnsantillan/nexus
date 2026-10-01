<div class="mx-auto w-full max-w-5xl">
    <x-star-header :star="$star" current="tools" />

    <div class="mt-8 space-y-10">
        <section aria-labelledby="policy-heading">
            <flux:heading size="lg" level="2" id="policy-heading">{{ __('New-tool policy') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Decides every tool you haven\'t switched yourself, including tools a server adds later.') }}</flux:text>

            <flux:radio.group wire:model.live="policy" variant="cards" class="mt-4 max-sm:flex-col" :aria-label="__('New-tool policy')">
                @foreach (App\Enums\NewToolPolicy::cases() as $option)
                    <flux:radio :value="$option->value" :label="$option->label()" :description="$option->description()" wire:key="policy-{{ $option->value }}" />
                @endforeach
            </flux:radio.group>
        </section>

        @if ($this->groups === [])
            <x-empty-state icon="wrench-screwdriver" :heading="__('No tools yet')">
                {{ __('This Star doesn\'t include any Connections. Choose them on its overview.') }}

                <x-slot:actions>
                    <flux:button variant="primary" :href="route('stars.show', $star)" wire:navigate>{{ __('Choose connections') }}</flux:button>
                </x-slot:actions>
            </x-empty-state>
        @else
            <section aria-labelledby="tools-heading">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <flux:heading size="lg" level="2" id="tools-heading">{{ __('Tools') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Clients see each tool as handle__tool. Your own switches stay when a server\'s tools are refreshed.') }}</flux:text>
                    </div>

                    <div class="w-full sm:w-72">
                        <flux:input wire:model.live.debounce.250ms="search" icon="magnifying-glass" :placeholder="__('Filter by name')" clearable :aria-label="__('Filter tools by name')" />
                    </div>
                </div>

                @foreach ($this->groups as $group)
                    <div wire:key="connection-{{ $group['connection']->id }}" class="mt-8">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <x-connector-logo :connector="$group['connection']->connector()" size="sm" />

                                <div class="min-w-0">
                                    <flux:heading size="lg" level="3" class="truncate">
                                        <flux:link :href="route('connections.show', $group['connection'])" variant="ghost" wire:navigate>{{ $group['connection']->name }}</flux:link>
                                    </flux:heading>
                                    <flux:text size="sm" class="mt-0.5">
                                        <span class="font-mono">{{ $group['connection']->handle }}</span>
                                        <x-account-label :connection="$group['connection']" separated />
                                        · {{ __(':enabled of :total on', ['enabled' => $group['enabled'], 'total' => $group['total']]) }}
                                    </flux:text>
                                </div>
                            </div>

                            @if ($group['tools'] !== [])
                                <div class="flex flex-wrap gap-2">
                                    <flux:button size="sm" wire:click="switchConnection({{ $group['connection']->id }}, 'on')">{{ blank($search) ? __('All on') : __('Matching on') }}</flux:button>
                                    <flux:button size="sm" wire:click="switchConnection({{ $group['connection']->id }}, 'off')">{{ blank($search) ? __('All off') : __('Matching off') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="switchConnection({{ $group['connection']->id }}, 'reset')">{{ __('Reset to policy') }}</flux:button>
                                </div>
                            @endif
                        </div>

                        @if ($group['total'] === 0)
                            <flux:text class="mt-3">
                                {{ __('Nexus has no tools for this Connection yet.') }}
                                <flux:link :href="route('connections.show', $group['connection'])" wire:navigate>{{ __('Refresh its tools') }}</flux:link>
                            </flux:text>
                        @elseif ($group['tools'] === [])
                            <flux:text class="mt-3">{{ __('No tools match ":search".', ['search' => trim($search)]) }}</flux:text>
                        @else
                            <flux:table class="mt-3">
                                <flux:table.columns>
                                    <flux:table.column class="w-0">{{ __('On') }}</flux:table.column>
                                    <flux:table.column>{{ __('Tool') }}</flux:table.column>
                                    <flux:table.column>{{ __('Hints') }}</flux:table.column>
                                    <flux:table.column>{{ __('Set by') }}</flux:table.column>
                                </flux:table.columns>

                                <flux:table.rows>
                                    @foreach ($group['tools'] as $tool)
                                        <flux:table.row :key="$tool->tool->id">
                                            <flux:table.cell>
                                                {{-- Keyed by its state, so a switch changed on the server is drawn afresh: Flux's switch keeps its own state otherwise. --}}
                                                <flux:switch
                                                    wire:key="switch-{{ $tool->tool->id }}-{{ $tool->enabled ? 'on' : 'off' }}"
                                                    :checked="$tool->enabled"
                                                    wire:click="switchTool({{ $tool->tool->id }}, {{ $tool->enabled ? 'false' : 'true' }})"
                                                    :aria-label="__('Switch :name on or off', ['name' => $tool->name])"
                                                />
                                            </flux:table.cell>
                                            <flux:table.cell class="max-w-md whitespace-normal!">
                                                <div class="break-all font-mono text-sm font-medium text-zinc-800 dark:text-white">{{ $tool->name }}</div>
                                                @if ($tool->tool->title !== null)
                                                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $tool->tool->title }}</div>
                                                @endif
                                                @if ($tool->tool->description !== null)
                                                    <flux:text size="sm" class="mt-1 line-clamp-2">{{ $tool->tool->description }}</flux:text>
                                                @endif
                                            </flux:table.cell>
                                            <flux:table.cell class="whitespace-normal!">
                                                <x-tool-hints :tool="$tool->tool" />
                                            </flux:table.cell>
                                            <flux:table.cell>
                                                @if ($tool->followsPolicy())
                                                    <flux:tooltip :content="__('Follows the new-tool policy: :policy.', ['policy' => $star->new_tool_policy->label()])">
                                                        <flux:badge size="sm">{{ __('Policy') }}</flux:badge>
                                                    </flux:tooltip>
                                                @else
                                                    <div class="flex items-center gap-1">
                                                        <flux:badge size="sm" color="blue">{{ __('Your choice') }}</flux:badge>
                                                        <flux:button size="xs" variant="ghost" wire:click="resetTool({{ $tool->tool->id }})" :aria-label="__('Reset :name to the policy', ['name' => $tool->name])">{{ __('Reset') }}</flux:button>
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
