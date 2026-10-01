<div class="mx-auto w-full max-w-5xl">
    <x-star-header :star="$star" current="overview" />

    <div class="mt-8 space-y-10">
        <section aria-labelledby="endpoint-heading">
            <flux:heading size="lg" level="2" id="endpoint-heading">{{ __('Endpoint') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Add this URL to your MCP client. It stays the same when you rename the Star.') }}</flux:text>

            <div class="mt-4 max-w-xl">
                <flux:input :value="$star->endpointUrl()" readonly copyable class:input="font-mono" :aria-label="__('Endpoint URL')" />
            </div>

            <dl class="mt-4 max-w-xl divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                    <dt><flux:text>{{ __('Access') }}</flux:text></dt>
                    <dd class="sm:col-span-2"><flux:text variant="strong">{{ $star->access_mode->label() }}</flux:text></dd>
                </div>

                <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                    <dt><flux:text>{{ __('Tools on') }}</flux:text></dt>
                    <dd class="sm:col-span-2">
                        <flux:link :href="route('stars.tools', $star)" wire:navigate>{{ __(':enabled of :total', $this->toolCounts) }}</flux:link>
                    </dd>
                </div>
            </dl>
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
                <flux:text class="mt-2">{{ __('Clients using its endpoint stop working, and its switches are removed. Your Connections stay. This can\'t be undone.') }}</flux:text>
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
