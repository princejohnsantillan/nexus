<div class="mx-auto w-full max-w-5xl" x-data="unsavedChangesGuard('leave-star')" x-bind="guard">
    <x-star-header :star="$star" current="overview" />

    <x-stat-strip class="mt-8">
        <x-stat-tile :label="__('Tools on')" :value="number_format($this->stats->toolsOn)" :secondary="__('of :total', ['total' => number_format($this->stats->tools)])" />
        <x-stat-tile :label="__('Calls · 24h')" :value="number_format($this->stats->calls)" :spark="$this->stats->calls > 0 ? $this->stats->callsByHour : null" />
        <x-stat-tile :label="__('Errors · 24h')" :value="number_format($this->stats->errors)" :secondary="$this->stats->errorRate()" :tone="$this->stats->errors > 0 ? 'danger' : null" />

        @if ($this->stats->lastCall === null)
            <x-stat-tile :label="__('Last call')" :value="__('No calls yet')" />
        @else
            <x-stat-tile :label="__('Last call')" :value="$this->stats->lastCall->created_at->diffForHumans(['short' => true])" :secondary="$this->stats->lastCall->client_name ?? $this->stats->lastCall->via->label()" />
        @endif
    </x-stat-strip>

    <div class="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <x-section-card id="setup" class="scroll-mt-6" :heading="__('Set up a client')" :description="__('Pick the client. You\'ll only see its steps.')">
            <x-slot:aside>
                <div x-data="{ copied: false }">
                    <flux:button
                        size="sm"
                        x-on:click="navigator.clipboard.writeText($refs.prompt.textContent).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                        x-bind:data-copied="copied"
                        data-setup-prompt-copy
                    >
                        <flux:icon.sparkles variant="micro" class="block size-3.5 text-accent-content [[data-copied]>&]:hidden" />
                        <flux:icon.check variant="micro" class="hidden size-3.5 [[data-copied]>&]:block" />
                        <span class="[[data-copied]>&]:hidden">{{ __('Copy setup as prompt') }}</span>
                        <span class="hidden [[data-copied]>&]:inline" role="status">{{ __('Copied') }}</span>
                    </flux:button>

                    <pre x-ref="prompt" hidden data-setup-prompt>{{ $this->setupPrompt }}</pre>
                </div>
            </x-slot:aside>

            <div class="-m-1 overflow-x-auto p-1">
                <flux:radio.group wire:model.live="client" variant="segmented" size="sm" class="w-max" :aria-label="__('Client')" data-setup-picker>
                    @foreach ($this->clients as $option)
                        <flux:radio :value="$option->value" wire:key="client-{{ $option->value }}" data-setup-client="{{ $option->value }}">
                            @if (in_array($option, $this->clientsThatCalled, true))
                                <span class="size-1.5 shrink-0 rounded-full bg-success" aria-hidden="true" data-setup-client-called></span>
                                <span>{{ $option->label() }}</span>
                                <span class="sr-only">{{ __('(has reached this Star)') }}</span>
                            @else
                                {{ $option->label() }}
                            @endif
                        </flux:radio>
                    @endforeach
                </flux:radio.group>
            </div>

            @php
                $addStep = $star->access_mode === App\Enums\StarAccessMode::Token ? 2 : 1;
                $checkStep = $addStep + ($this->setup['login'] !== null ? 2 : 1);
            @endphp

            <ol class="mt-6 space-y-6" data-setup-steps>
                @if ($star->access_mode === App\Enums\StarAccessMode::Token)
                    <x-step :number="1" :heading="__('Put a token in your shell')">
                        <x-slot:aside>
                            <flux:link :href="route('stars.access', ['star' => $star, 'new_token' => $this->chosenClient->label()])" class="font-medium" wire:navigate data-setup-create-token>{{ __('Create a token for :client →', ['client' => $this->chosenClient->label()]) }}</flux:link>
                        </x-slot:aside>

                        <x-code-panel :file="App\Stars\ClientSetup::SHELL_PROFILE" :code="App\Stars\ClientSetup::tokenExport($star)" />
                    </x-step>
                @endif

                <x-step :number="$addStep" :heading="__('Add :star to :client', ['star' => $star->name, 'client' => $this->chosenClient->label()])">
                    @if ($this->setup['instruction'] !== null)
                        <flux:text>{{ $this->setup['instruction'] }}</flux:text>
                    @endif

                    <x-code-panel :code="$this->setup['snippet']" :file="$this->setup['file']" :label="$this->setup['instruction'] !== null ? __('URL') : null" />
                </x-step>

                @if ($this->setup['login'] !== null)
                    <x-step :number="$addStep + 1" :heading="__('Sign in and approve it')">
                        <flux:text>{{ $this->setup['login']['instruction'] }}</flux:text>

                        @if ($this->setup['login']['snippet'] !== null)
                            <x-code-panel :code="$this->setup['login']['snippet']" />
                        @endif
                    </x-step>
                @endif

                <x-step :number="$checkStep" :heading="__('Check it works')" pending>
                    <div aria-live="polite">
                        @island(name: 'check', always: true)
                            @if ($this->heardAt !== null)
                                <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2 rounded-lg border border-success/25 bg-success-wash p-4" wire:key="setup-check-heard" data-setup-check="heard">
                                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-success/15">
                                        <flux:icon.check variant="mini" class="size-4 text-success" />
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-zinc-950 wrap-anywhere dark:text-white">{{ __(':client reached :star :time', ['client' => $this->chosenClient->label(), 'star' => $this->star->name, 'time' => $this->heardAt->diffForHumans()]) }}</p>
                                        <flux:text class="mt-0.5">{{ __('It\'s set up. Its calls show in Activity.') }}</flux:text>
                                    </div>

                                    <flux:link :href="route('activity.index', ['star' => $this->star->public_id])" class="shrink-0 text-sm font-medium max-sm:w-full max-sm:ps-11.5" wire:navigate>{{ __('See Activity →') }}</flux:link>
                                </div>
                            @else
                                <div
                                    @if ($this->isListening) wire:poll.5s wire:key="setup-check-listening-{{ $this->listeningSince }}" @else wire:key="setup-check-stopped" @endif
                                    @class([
                                        'rounded-lg border p-4',
                                        'border-accent/20 bg-accent-wash' => $this->isListening,
                                        'border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-white/5' => ! $this->isListening,
                                    ])
                                    data-setup-check="{{ $this->isListening ? 'listening' : 'stopped' }}"
                                >
                                    <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2">
                                        @if ($this->isListening)
                                            <span class="relative flex size-8 shrink-0 items-center justify-center rounded-full bg-accent/10">
                                                <span class="absolute size-4 rounded-full bg-accent/25 motion-safe:animate-ping"></span>
                                                <span class="relative size-2 rounded-full bg-accent"></span>
                                            </span>
                                        @else
                                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-200/70 dark:bg-white/10">
                                                <span class="size-2 rounded-full bg-zinc-400 dark:bg-zinc-500"></span>
                                            </span>
                                        @endif

                                        <div class="min-w-0 flex-1">
                                            @if ($this->isListening)
                                                <p class="text-sm font-semibold text-zinc-950 dark:text-white">{{ __('Waiting for a call from :client…', ['client' => $this->chosenClient->label()]) }}</p>
                                                <flux:text class="mt-0.5">{{ $this->checkHint }}</flux:text>
                                            @else
                                                <p class="text-sm font-semibold text-zinc-950 dark:text-white">{{ __('No call from :client yet', ['client' => $this->chosenClient->label()]) }}</p>
                                                <flux:text class="mt-0.5">{{ __('Stopped listening after 10 minutes. Listen again once you\'ve set it up.') }}</flux:text>
                                            @endif
                                        </div>

                                        <div class="flex shrink-0 items-center gap-3 max-sm:w-full max-sm:ps-11.5">
                                            <button type="button" class="text-sm font-medium text-accent-content hover:underline" wire:click="$toggle('showTips')" aria-expanded="{{ $this->showTips ? 'true' : 'false' }}" aria-controls="setup-tips" data-setup-troubleshoot>{{ __('Troubleshoot') }}</button>

                                            @unless ($this->isListening)
                                                <flux:button size="sm" wire:click="listenAgain">{{ __('Listen again') }}</flux:button>
                                            @endunless
                                        </div>
                                    </div>

                                    @if ($this->showTips)
                                        <ul id="setup-tips" class="mt-3 list-disc space-y-1 border-t border-accent/10 ps-[3.75rem] pt-3 text-sm text-zinc-600 marker:text-zinc-400 dark:text-zinc-300" data-setup-tips>
                                            @foreach ($this->troubleshooting as $tip)
                                                <li wire:key="tip-{{ $loop->index }}">{{ $tip }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endif
                        @endisland
                    </div>
                </x-step>
            </ol>

            <x-slot:hint>
                @if ($star->access_mode === App\Enums\StarAccessMode::SignedUrl)
                    {{ __('The snippet holds the Star\'s signed URL. Keep it out of shared or committed files; if it leaks, rotate it on the') }}
                    <flux:link :href="route('stars.access', $star)" wire:navigate>{{ __('Access page') }}</flux:link>
                    {{ __('and set up your clients again.') }}
                @elseif ($star->access_mode === App\Enums\StarAccessMode::OAuth)
                    {{ __('Clients take only the URL, then sign in to Nexus, where you approve each for this Star. Revoke them on the') }}
                    <flux:link :href="route('stars.access', $star)" wire:navigate>{{ __('Access page') }}</flux:link>{{ __('.') }}
                @else
                    <span class="wrap-anywhere">{{ __('Each snippet reads the token from :variable, so it never sits in a config file.', ['variable' => $this->tokenVariable]) }}</span>
                @endif
            </x-slot:hint>
        </x-section-card>

        <div class="grid gap-6">
            <form wire:submit="save" class="grid gap-6">
                @if ($this->connections->isEmpty())
                    <x-section-card :heading="__('Connections')" :description="__('The Star offers the tools of the Connections you tick.')">
                        <x-empty-state icon="link" :heading="__('No Connections yet')">
                            {{ __('Connect a remote MCP server, then come back to add it to this Star.') }}

                            <x-slot:actions>
                                <flux:button variant="primary" icon="plus" :href="route('connections.add')" wire:navigate>{{ __('Add connection') }}</flux:button>
                            </x-slot:actions>
                        </x-empty-state>
                    </x-section-card>
                @else
                    <x-section-card :heading="__('Connections')" :description="__('The Star offers the tools of the Connections you tick.')">
                        <x-connection-picker :connections="$this->connections" :unsaved="$this->changes->connectionIds" wire:model.live="connectionIds" :aria-label="__('Connections')" />

                        <x-slot:hint>{{ __('Taking one out forgets the switches you set for its tools here.') }}</x-slot:hint>
                    </x-section-card>
                @endif

                <x-section-card :heading="__('Details')" :description="__('Agents see the description, so say what the Star is for.')">
                    <div class="space-y-6">
                        <flux:input wire:model.live="name" :label="__('Name')" maxlength="100" />
                        <flux:textarea wire:model.live="description" :label="__('Description')" :badge="__('Optional')" rows="2" maxlength="500" />
                    </div>

                    <x-slot:hint>{{ __('Renaming keeps the endpoint URL, so clients keep working.') }}</x-slot:hint>
                </x-section-card>
            </form>

            <x-danger-card :heading="__('Delete this Star')" :description="__('Clients using its endpoint stop working, and its tokens and connected apps are revoked. Your Connections stay.')">
                <x-slot:actions>
                    <flux:modal.trigger name="delete-star">
                        <flux:button variant="danger" size="sm">{{ __('Delete Star') }}</flux:button>
                    </flux:modal.trigger>
                </x-slot:actions>
            </x-danger-card>
        </div>
    </div>

    <x-unsaved-changes-bar :unsaved="! $this->changes->isEmpty()" :consequences="$this->changes->consequences" />

    <flux:modal name="leave-star" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Leave without saving?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Your changes to this Star\'s Connections and details aren\'t saved. Leaving the page discards them.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Stay') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" x-on:click="leave()">{{ __('Leave without saving') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="delete-star" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg" class="wrap-anywhere">{{ __('Delete :name?', ['name' => $star->name]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('Clients using its endpoint stop working, its connected apps are revoked and its switches are removed. Your Connections stay. This can\'t be undone.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="delete">{{ __('Delete Star') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
