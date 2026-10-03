<div class="mx-auto w-full max-w-5xl">
    <x-star-header :star="$star" current="overview" />

    <div class="mt-8 space-y-10">
        <section aria-labelledby="endpoint-heading">
            <flux:heading size="lg" level="2" id="endpoint-heading">{{ __('Endpoint') }}</flux:heading>
            @if ($star->access_mode === App\Enums\StarAccessMode::SignedUrl)
                <flux:text class="mt-1">{{ __('Add this signed URL to your MCP client. It works by itself, so anyone who has it can use the Star: keep it private, and rotate it on the Access page if it leaks.') }}</flux:text>
            @elseif ($star->access_mode === App\Enums\StarAccessMode::OAuth)
                <flux:text class="mt-1">{{ __('Add this URL to your MCP client. When it connects, it sends you to Nexus to sign in and approve it for this Star. The URL stays the same when you rename the Star.') }}</flux:text>
            @else
                <flux:text class="mt-1">{{ __('Add this URL to your MCP client. It stays the same when you rename the Star.') }}</flux:text>
            @endif

            <div class="mt-4 max-w-xl">
                <flux:input :value="$star->clientUrl()" readonly copyable class:input="font-mono" :aria-label="__('Endpoint URL')" />
            </div>

            <dl class="mt-4 max-w-xl divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                    <dt><flux:text>{{ __('Access') }}</flux:text></dt>
                    <dd class="sm:col-span-2">
                        <flux:link :href="route('stars.access', $star)" wire:navigate>{{ $star->access_mode->label() }}</flux:link>
                    </dd>
                </div>

                <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                    <dt><flux:text>{{ __('Tools on') }}</flux:text></dt>
                    <dd class="sm:col-span-2">
                        <flux:link :href="route('stars.tools', $star)" wire:navigate>{{ __(':enabled of :total', $this->toolCounts) }}</flux:link>
                    </dd>
                </div>
            </dl>
        </section>

        <section aria-labelledby="setup-heading">
            <flux:heading size="lg" level="2" id="setup-heading">{{ __('Set up a client') }}</flux:heading>
            @if ($star->access_mode === App\Enums\StarAccessMode::SignedUrl)
                <flux:text class="mt-1">
                    {{ __('Each snippet below holds the Star\'s signed URL and nothing else. Keep them out of shared or committed config files; if the URL leaks, rotate it on the') }}
                    <flux:link :href="route('stars.access', $star)" wire:navigate>{{ __('Access page') }}</flux:link>
                    {{ __('and set up your clients again.') }}
                </flux:text>
            @elseif ($star->access_mode === App\Enums\StarAccessMode::OAuth)
                <flux:text class="mt-1">
                    {{ __('Each client below takes only the URL, then signs in to Nexus: you sign in too if you need to, and approve it for this Star. The apps you approved are listed on the') }}
                    <flux:link :href="route('stars.access', $star)" wire:navigate>{{ __('Access page') }}</flux:link>{{ __(', where you can revoke each of them.') }}
                </flux:text>
            @else
                <flux:text class="mt-1">
                    {{ __('Create a token on the') }}
                    <flux:link :href="route('stars.access', $star)" wire:navigate>{{ __('Access page') }}</flux:link>
                    {{ __('and put it in the :variable environment variable, e.g. in your shell profile. Each snippet below reads it from there, so the token never sits in a config file.', ['variable' => $this->tokenVariable]) }}
                </flux:text>

                <div class="mt-4 max-w-3xl">
                    <x-code-panel :code="'export '.$this->tokenVariable.'=nxs_…'" />
                </div>
            @endif

            <div class="mt-6 max-w-3xl space-y-6">
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
        </section>

        <section aria-labelledby="connections-heading">
            <flux:heading size="lg" level="2" id="connections-heading">{{ __('Connections') }}</flux:heading>
            <flux:text class="mt-1">{{ __('The Star includes the tools of the Connections you choose. Taking one out forgets the switches you set for its tools here.') }}</flux:text>

            @if ($this->connections->isEmpty())
                <x-empty-state icon="link" :heading="__('No Connections yet')" class="mt-6">
                    {{ __('Connect a remote MCP server, then come back to add it to this Star.') }}

                    <x-slot:actions>
                        <flux:button variant="primary" icon="plus" :href="route('connections.add')" wire:navigate>{{ __('Add connection') }}</flux:button>
                    </x-slot:actions>
                </x-empty-state>
            @else
                <form wire:submit="saveConnections" class="mt-6 max-w-xl space-y-6">
                    <x-connection-picker :connections="$this->connections" wire:model="connectionIds" :aria-label="__('Connections')" />

                    <flux:button type="submit">{{ __('Save connections') }}</flux:button>
                </form>
            @endif
        </section>

        <section aria-labelledby="details-heading">
            <flux:heading size="lg" level="2" id="details-heading">{{ __('Details') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Renaming the Star keeps its endpoint URL, so clients keep working.') }}</flux:text>

            <form wire:submit="saveDetails" class="mt-6 max-w-xl space-y-6">
                <flux:input wire:model="name" :label="__('Name')" maxlength="100" />
                <flux:textarea wire:model="description" :label="__('Description')" :badge="__('Optional')" :description="__('Agents see this, so say what the Star is for.')" rows="2" maxlength="500" />

                <flux:button type="submit">{{ __('Save details') }}</flux:button>
            </form>
        </section>

        <section aria-labelledby="delete-heading">
            <flux:heading size="lg" level="2" id="delete-heading">{{ __('Delete Star') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Removes this Star and its switches. Your Connections stay.') }}</flux:text>

            <flux:modal.trigger name="delete-star">
                <flux:button variant="danger" class="mt-4">{{ __('Delete Star') }}</flux:button>
            </flux:modal.trigger>
        </section>
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
