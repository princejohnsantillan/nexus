<div class="mx-auto w-full max-w-5xl">
    <flux:link :href="route('connections.index')" variant="subtle" class="text-sm" wire:navigate>&larr; {{ __('Connections') }}</flux:link>

    <flux:heading size="xl" level="1" class="mt-3">{{ __('Add connection') }}</flux:heading>
    <flux:text class="mt-2">{{ __('Choose the MCP server to connect. Nexus signs in to it once, and every Star you add it to can use its tools. Connecting a service you already use adds another account.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->limitMessage !== null)
        <flux:callout icon="exclamation-triangle" color="amber" class="mb-6" :heading="__('Connection limit reached')">
            <flux:callout.text>{{ $this->limitMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($this->connectors as $connector)
            <flux:card class="flex flex-col" wire:key="connector-{{ $connector->key }}" data-connector="{{ $connector->key }}">
                <div class="flex items-start justify-between gap-3">
                    <x-connector-logo :connector="$connector" />

                    <div class="flex flex-wrap justify-end gap-1">
                        @if ($connector->preview)
                            <flux:badge size="sm" color="sky">{{ __('Preview') }}</flux:badge>
                        @endif

                        @unless ($connector->isAvailable())
                            <flux:badge size="sm" color="zinc">{{ __('Not available yet') }}</flux:badge>
                        @endunless
                    </div>
                </div>

                <flux:heading size="lg" level="2" class="mt-4">{{ $connector->name }}</flux:heading>
                <flux:text class="mt-1 flex-1">{{ $connector->summary }}</flux:text>

                @if ($connector->unavailableReason() !== null)
                    <flux:text size="sm" class="mt-3">{{ $connector->unavailableReason() }}</flux:text>
                @endif

                <div class="mt-4 flex items-center justify-between gap-3">
                    @if ($connector->isAvailable() && $this->limitMessage === null)
                        <flux:button wire:click="startConnecting('{{ $connector->key }}')">{{ __('Connect') }}</flux:button>
                    @else
                        <flux:button disabled>{{ __('Connect') }}</flux:button>
                    @endif

                    <flux:link :href="$connector->docsUrl" external rel="noopener noreferrer" variant="subtle" class="text-sm">{{ __('Docs') }}</flux:link>
                </div>
            </flux:card>
        @endforeach

        <flux:card class="flex flex-col">
            <x-connector-logo />

            <flux:heading size="lg" level="2" class="mt-4">{{ __('Custom MCP server') }}</flux:heading>
            <flux:text class="mt-1 flex-1">{{ __('Any remote MCP server, by URL. It can need no sign-in, or a header such as an API key.') }}</flux:text>

            <div class="mt-4">
                @if ($this->limitMessage === null)
                    <flux:button :href="route('connections.add-custom')" wire:navigate>{{ __('Connect') }}</flux:button>
                @else
                    <flux:button disabled>{{ __('Connect') }}</flux:button>
                @endif
            </div>
        </flux:card>
    </div>

    <flux:text size="sm" class="mt-8 text-zinc-400 dark:text-zinc-500">{{ $this->attribution }}</flux:text>

    <flux:modal name="connect" class="w-full max-w-lg">
        @if ($this->connector !== null)
            <form wire:submit="connect" class="space-y-6">
                <div>
                    <div class="flex items-center gap-3">
                        <x-connector-logo :connector="$this->connector" size="sm" />
                        <flux:heading size="lg">{{ __('Connect :name', ['name' => $this->connector->name]) }}</flux:heading>
                    </div>

                    <flux:text class="mt-2">{{ __('Already connected :name? Connecting it again adds another account.', ['name' => $this->connector->name]) }}</flux:text>
                </div>

                <flux:input wire:model="name" :label="__('Name')" :description="__('What you call this account in Nexus.')" maxlength="100" />

                <flux:input
                    wire:model="handle"
                    :label="__('Handle')"
                    :description="__('Its tools appear in Stars as handle__tool, so the handle can\'t be changed later.')"
                    :maxlength="\App\Models\Connection::HANDLE_MAX_LENGTH"
                    class:input="font-mono"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                />

                <flux:input wire:model="description" :label="__('Use this account for')" :badge="__('Optional')" :description="__('Helps agents pick the right account when you connect a service more than once, e.g. \'work repositories\'.')" maxlength="200" />

                @if (count($this->connector->methods()) > 1)
                    <flux:radio.group wire:model.live="method" :label="__('Sign-in')" variant="cards" class="flex-col">
                        @foreach ($this->connector->methods() as $option)
                            <flux:radio
                                :value="$option->value"
                                :label="$option->label($this->connector->name)"
                                :description="$this->connector->whyUnavailable($option) === null
                                    ? $option->description($this->connector->name)
                                    : __('Not available yet. :reason', ['reason' => $this->connector->whyUnavailable($option)])"
                                :disabled="$this->connector->whyUnavailable($option) !== null"
                            />
                        @endforeach
                    </flux:radio.group>
                @endif

                <flux:error name="method" />

                @if ($method === App\Enums\SignInMethod::Token->value && $this->connector->token !== null)
                    <div class="space-y-3">
                        <flux:text>{{ $this->connector->token->instructions }}</flux:text>

                        <flux:link :href="$this->connector->token->consoleUrl" external rel="noopener noreferrer" class="text-sm">{{ __('Create a token on :name', ['name' => $this->connector->name]) }} &nearr;</flux:link>
                    </div>

                    <flux:input
                        wire:model="token"
                        type="password"
                        viewable
                        :label="__('Token')"
                        :description="__('Stored encrypted. Nexus sends it as :header and checks it by loading the tools.', ['header' => $this->connector->token->headerName.': '.$this->connector->token->valuePrefix.'…'])"
                        autocomplete="off"
                    />
                @endif

                <flux:error name="limit" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary">{{ __('Connect and load tools') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
