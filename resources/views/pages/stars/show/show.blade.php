<div class="mx-auto w-full max-w-5xl">
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
        <x-section-card :heading="__('Set up a client')">
            @if ($star->access_mode === App\Enums\StarAccessMode::SignedUrl)
                <flux:text>
                    {{ __('Each snippet below holds the Star\'s signed URL and nothing else. Keep them out of shared or committed config files; if the URL leaks, rotate it on the') }}
                    <flux:link :href="route('stars.access', $star)" wire:navigate>{{ __('Access page') }}</flux:link>
                    {{ __('and set up your clients again.') }}
                </flux:text>
            @elseif ($star->access_mode === App\Enums\StarAccessMode::OAuth)
                <flux:text>
                    {{ __('Each client below takes only the URL, then signs in to Nexus: you sign in too if you need to, and approve it for this Star. The apps you approved are listed on the') }}
                    <flux:link :href="route('stars.access', $star)" wire:navigate>{{ __('Access page') }}</flux:link>{{ __(', where you can revoke each of them.') }}
                </flux:text>
            @else
                <flux:text>
                    {{ __('Create a token on the') }}
                    <flux:link :href="route('stars.access', $star)" wire:navigate>{{ __('Access page') }}</flux:link>
                    {{ __('and put it in the :variable environment variable, e.g. in your shell profile. Each snippet below reads it from there, so the token never sits in a config file.', ['variable' => $this->tokenVariable]) }}
                </flux:text>

                <x-code-panel :code="'export '.$this->tokenVariable.'=nxs_…'" class="mt-4" />
            @endif

            <div class="mt-6 space-y-6">
                @foreach ($this->clientSetup as $setup)
                    <div wire:key="setup-{{ $loop->index }}">
                        <flux:heading level="3">{{ $setup['client'] }}</flux:heading>
                        <flux:text size="sm" class="mt-1">
                            @if ($setup['file'] !== null)
                                {{ __('Add to') }} <span class="font-mono">{{ $setup['file'] }}</span>:
                            @elseif ($setup['instruction'] !== null)
                                {{ $setup['instruction'] }}
                            @else
                                {{ __('Run in a terminal:') }}
                            @endif
                        </flux:text>

                        <x-code-panel :code="$setup['snippet']" :file="$setup['file']" :label="$setup['instruction'] !== null ? __('URL') : null" class="mt-2" />

                        @if ($setup['login'] !== null)
                            <flux:text size="sm" class="mt-3">{{ $setup['login']['instruction'] }}</flux:text>

                            @if ($setup['login']['snippet'] !== null)
                                <x-code-panel :code="$setup['login']['snippet']" class="mt-2" />
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </x-section-card>

        <div class="grid gap-6">
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
                <x-section-card as="form" wire:submit="saveConnections" :heading="__('Connections')" :description="__('The Star offers the tools of the Connections you tick.')">
                    <x-connection-picker :connections="$this->connections" wire:model="connectionIds" :aria-label="__('Connections')" />

                    <x-slot:hint>{{ __('Taking one out forgets the switches you set for its tools here.') }}</x-slot:hint>
                    <x-slot:actions>
                        <flux:button type="submit" variant="primary" size="sm">{{ __('Save connections') }}</flux:button>
                    </x-slot:actions>
                </x-section-card>
            @endif

            <x-section-card as="form" wire:submit="saveDetails" :heading="__('Details')" :description="__('Agents see the description, so say what the Star is for.')">
                <div class="space-y-6">
                    <flux:input wire:model="name" :label="__('Name')" maxlength="100" />
                    <flux:textarea wire:model="description" :label="__('Description')" :badge="__('Optional')" rows="2" maxlength="500" />
                </div>

                <x-slot:hint>{{ __('Renaming keeps the endpoint URL, so clients keep working.') }}</x-slot:hint>
                <x-slot:actions>
                    <flux:button type="submit" variant="primary" size="sm">{{ __('Save details') }}</flux:button>
                </x-slot:actions>
            </x-section-card>

            <x-danger-card :heading="__('Delete this Star')" :description="__('Clients using its endpoint stop working, and its tokens and connected apps are revoked. Your Connections stay.')">
                <x-slot:actions>
                    <flux:modal.trigger name="delete-star">
                        <flux:button variant="danger" size="sm">{{ __('Delete Star') }}</flux:button>
                    </flux:modal.trigger>
                </x-slot:actions>
            </x-danger-card>
        </div>
    </div>

    <flux:modal name="delete-star" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete :name?', ['name' => $star->name]) }}</flux:heading>
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
