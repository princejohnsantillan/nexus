<div class="mx-auto w-full max-w-5xl">
    <x-star-header :star="$star" current="tools" />

    <div class="mt-8 space-y-10">
        <x-section-card :heading="__('New-tool policy')" :description="__('Decides every tool you haven\'t switched yourself, including tools a server adds later.')">
            <flux:radio.group wire:model.live="policy" variant="cards" class="max-sm:flex-col" :aria-label="__('New-tool policy')">
                @foreach (App\Enums\NewToolPolicy::cases() as $option)
                    <flux:radio :value="$option->value" :label="$option->label()" :description="$option->description()" wire:key="policy-{{ $option->value }}" />
                @endforeach
            </flux:radio.group>
        </x-section-card>

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
                    <div class="min-w-0 sm:max-w-xl">
                        <flux:heading size="lg" level="2" id="tools-heading">{{ __('Tools') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Decide by risk first: a group\'s switch gives each of its tools your own choice. Clients see each tool as handle__tool, and your own switches stay when a server\'s tools are refreshed. Choose a tool\'s name for its details.') }}</flux:text>
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
                            <div class="mt-4 space-y-3">
                                @foreach ($group['risks'] as $risk)
                                    @php
                                        $riskId = 'risk-'.$group['connection']->id.'-'.$risk['risk']->value;
                                        $allOn = $risk['enabled'] === count($risk['tools']);
                                        $names = ['risk' => $risk['risk']->label(), 'connection' => $group['connection']->name];
                                    @endphp

                                    <div wire:key="{{ $riskId }}" x-data="{ open: true }" class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-white/[4%]">
                                        <div class="flex items-center gap-2 bg-zinc-50 py-2 ps-2 pe-4 dark:bg-black/15">
                                            <button
                                                type="button"
                                                class="flex min-w-0 flex-1 items-center gap-3 rounded-md px-2 py-1 text-start"
                                                x-on:click="open = ! open"
                                                aria-expanded="true"
                                                x-bind:aria-expanded="open ? 'true' : 'false'"
                                                aria-controls="{{ $riskId }}-tools"
                                            >
                                                <flux:icon.chevron-down variant="micro" class="shrink-0 text-zinc-600 transition-transform dark:text-zinc-300" x-bind:class="open ? '' : '-rotate-90'" />
                                                <x-tool-risk :risk="$risk['risk']" />
                                                <span class="truncate text-sm text-zinc-600 dark:text-zinc-300">{{ __(':enabled of :total on', ['enabled' => $risk['enabled'], 'total' => count($risk['tools'])]) }}</span>
                                            </button>

                                            @if ($risk['ownChoices'] > 0)
                                                <flux:button size="xs" variant="ghost" wire:click="switchGroup({{ $group['connection']->id }}, '{{ $risk['risk']->value }}', 'reset')" :aria-label="__('Reset the :risk tools of :connection to the policy', $names)">{{ __('Reset') }}</flux:button>
                                            @endif

                                            <span class="text-sm whitespace-nowrap text-zinc-600 max-sm:hidden dark:text-zinc-300">{{ match (true) { $allOn => __('All on'), $risk['enabled'] === 0 => __('All off'), default => __('Some on') } }}</span>

                                            {{-- Keyed by its state, so a switch changed on the server is drawn afresh: Flux's switch keeps its own state otherwise. It reports a toggle by click, Enter or Space as a change event, never a keyboard click. --}}
                                            <flux:switch
                                                wire:key="group-switch-{{ $riskId }}-{{ $allOn ? 'on' : 'off' }}"
                                                :checked="$allOn"
                                                wire:change="switchGroup({{ $group['connection']->id }}, '{{ $risk['risk']->value }}', '{{ $allOn ? 'off' : 'on' }}')"
                                                :aria-label="__('Switch every :risk tool of :connection on or off', $names)"
                                            />
                                        </div>

                                        <div id="{{ $riskId }}-tools" x-show="open" class="divide-y divide-zinc-200 border-t border-zinc-200 dark:divide-white/10 dark:border-white/10">
                                            @foreach ($risk['tools'] as $tool)
                                                <x-permission-row wire:key="tool-{{ $tool->tool->id }}" :title="$tool->tool->title" :description="$tool->tool->description">
                                                    <x-slot:name><x-tool-flyout.trigger :tool="$tool->tool">{{ $tool->name }}</x-tool-flyout.trigger></x-slot:name>
                                                    <x-slot:hints><x-tool-hints :tool="$tool->tool" /></x-slot:hints>

                                                    {{-- Keyed by its state, so a switch changed on the server is drawn afresh: Flux's switch keeps its own state otherwise. It reports a toggle by click, Enter or Space as a change event, never a keyboard click. --}}
                                                    <flux:switch
                                                        wire:key="switch-{{ $tool->tool->id }}-{{ $tool->enabled ? 'on' : 'off' }}"
                                                        :checked="$tool->enabled"
                                                        wire:change="switchTool({{ $tool->tool->id }}, {{ $tool->enabled ? 'false' : 'true' }})"
                                                        :aria-label="__('Switch :name on or off', ['name' => $tool->name])"
                                                    />

                                                    @if ($tool->followsPolicy())
                                                        <flux:tooltip :content="__('Follows the new-tool policy: :policy.', ['policy' => $star->new_tool_policy->label()])">
                                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Policy') }}</span>
                                                        </flux:tooltip>
                                                    @else
                                                        <span class="text-xs text-zinc-600 sm:whitespace-nowrap dark:text-zinc-300">
                                                            {{ __('Your choice') }} <span aria-hidden="true">·</span>
                                                            <button type="button" class="font-medium text-accent-content hover:underline" wire:click="resetTool({{ $tool->tool->id }})" aria-label="{{ __('Reset :name to the policy', ['name' => $tool->name]) }}">{{ __('Reset') }}</button>
                                                        </span>
                                                    @endif
                                                </x-permission-row>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif
    </div>

    <x-tool-flyout :details="$this->toolDetails">
        @if ($this->detailsTool !== null)
            @php($detailsTool = $this->detailsTool)

            <x-slot:switch>
                <div class="flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <flux:heading level="3">{{ $detailsTool->enabled ? __('On in :star', ['star' => $star->name]) : __('Off in :star', ['star' => $star->name]) }}</flux:heading>
                        <flux:text size="sm" class="mt-0.5">
                            @if ($detailsTool->followsPolicy())
                                {{ __('Follows the new-tool policy: :policy.', ['policy' => $star->new_tool_policy->label()]) }}
                            @else
                                {{ __('Your choice') }} <span aria-hidden="true">·</span>
                                <button type="button" class="font-medium text-accent-content hover:underline" wire:click="resetTool({{ $detailsTool->tool->id }})">{{ __('Reset to the policy') }}</button>
                            @endif
                        </flux:text>
                    </div>

                    {{-- Keyed by its state, so a switch changed on the server is drawn afresh: Flux's switch keeps its own state otherwise. It reports a toggle by click, Enter or Space as a change event, never a keyboard click. --}}
                    <flux:switch
                        wire:key="details-switch-{{ $detailsTool->tool->id }}-{{ $detailsTool->enabled ? 'on' : 'off' }}"
                        :checked="$detailsTool->enabled"
                        wire:change="switchTool({{ $detailsTool->tool->id }}, {{ $detailsTool->enabled ? 'false' : 'true' }})"
                        :aria-label="__('Switch :name on or off in :star', ['name' => $detailsTool->name, 'star' => $star->name])"
                    />
                </div>
            </x-slot:switch>
        @endif
    </x-tool-flyout>
</div>
