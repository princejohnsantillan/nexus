<div class="mx-auto w-full max-w-3xl">
    <flux:link :href="App\Stars\ReturnToStar::addMoreUrl($this->returnStar)" variant="subtle" class="text-sm" wire:navigate>&larr; {{ __('Connections') }}</flux:link>

    <flux:heading size="xl" level="1" class="mt-3">{{ __('Custom MCP server') }}</flux:heading>
    <flux:text class="mt-2">{{ __('Connect any remote MCP server by its URL. Nexus loads its tools as soon as you save, or once you sign in.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->returnStar !== null)
        <x-adding-to-star :star="$this->returnStar" class="mb-6 rounded-lg border border-accent/20 px-4 py-3" />
    @endif

    {{-- At the limit, submitting opens the upgrade prompt instead of saving. The server refuses a save past the limit all the same. --}}
    <form
        @if ($this->isAtConnectionLimit)
            x-data
            x-on:submit.prevent="$flux.modal('connection-limit').show()"
        @else
            wire:submit="save"
        @endif
        class="space-y-6"
        data-custom-connection-form
    >
        <flux:input wire:model="name" :label="__('Name')" :description="__('What you call this server in Nexus.')" placeholder="DeepWiki" maxlength="100" />

        <div class="space-y-3">
            <flux:input
                wire:model="handle"
                :label="__('Handle')"
                :description="__('Lowercase letters, digits and dashes, starting with a letter.')"
                placeholder="deepwiki"
                :maxlength="\App\Models\Connection::HANDLE_MAX_LENGTH"
                class:input="font-mono"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
            />

            <x-handle-preview :handle="$handle" />
        </div>

        <flux:input wire:model="description" :label="__('Use this account for')" :badge="__('Optional')" :description="__('Helps agents pick the right account when you connect a service more than once, e.g. \'work repositories\'.')" maxlength="200" />

        <flux:input wire:model="url" type="url" :label="__('Server URL')" :description="__('The server\'s MCP endpoint. It must use HTTPS and be on the public internet.')" placeholder="https://mcp.example.com/mcp" autocomplete="off" />

        <flux:radio.group wire:model.live="authType" :label="__('Sign-in')" variant="cards" class="max-sm:flex-col">
            <flux:radio value="none" :label="__('No auth')" :description="__('The server needs no sign-in.')" />
            <flux:radio value="header" :label="__('Header')" :description="__('Nexus sends a header, such as an API key, with every request.')" />
            <flux:radio value="oauth" :label="__('OAuth')" :description="__('You approve Nexus on the server\'s own sign-in page.')" />
        </flux:radio.group>

        @if ($authType === 'header')
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="headerName" :label="__('Header name')" placeholder="Authorization" autocomplete="off" autocapitalize="off" spellcheck="false" />

                <flux:input
                    wire:model="headerValue"
                    type="password"
                    viewable
                    :label="__('Header value')"
                    :description="__('Stored encrypted. Include any prefix the server expects, such as Bearer.')"
                    placeholder="Bearer …"
                    autocomplete="off"
                />
            </div>
        @endif

        @if ($authType === 'oauth')
            <x-own-oauth-app :callback-url="$this->callbackUrl" />
        @endif

        <flux:error name="limit" />

        <div class="flex flex-wrap items-center justify-end gap-x-4 gap-y-3">
            @if ($this->returnStar !== null)
                <flux:text class="me-auto min-w-0 wrap-anywhere" data-starts-on>{{ $this->returnStar->new_tool_policy->startsOnIn($this->returnStar->name) }}</flux:text>
            @endif

            <div class="flex gap-2">
                <flux:button variant="ghost" :href="$this->returnStar === null ? route('connections.index').'#add-more' : route('stars.show', $this->returnStar)" wire:navigate>{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ $authType === 'oauth' ? __('Save and sign in') : __('Save and load tools') }}</flux:button>
            </div>
        </div>
    </form>

    @if ($this->isAtConnectionLimit)
        <x-limit-modal for="connections" />
    @endif
</div>
