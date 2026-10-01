<div class="mx-auto w-full max-w-5xl">
    <x-connection-header :connection="$connection" current="overview" />

    <div class="mt-8 space-y-10">
        @if ($this->needsOAuthSignIn)
            <flux:callout icon="key" :color="$connection->status->color()" :heading="__('Sign in to use this Connection')">
                <flux:callout.text>{{ $connection->last_error ?? __('Nexus needs you to approve it on the server\'s own sign-in page before it can load the tools.') }}</flux:callout.text>

                <x-slot name="actions">
                    <flux:button :href="route('connections.connect', $connection)" variant="primary" size="sm">{{ __('Reconnect') }}</flux:button>
                </x-slot>
            </flux:callout>
        @elseif (filled($connection->last_error))
            <flux:callout icon="exclamation-triangle" :color="$connection->status->color()" :heading="__('Nexus couldn\'t load this Connection\'s tools')">
                <flux:callout.text>{{ $connection->last_error }}</flux:callout.text>
            </flux:callout>
        @endif

        <section aria-labelledby="status-heading">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <flux:heading size="lg" level="2" id="status-heading">{{ __('Status') }}</flux:heading>
                <flux:button icon="arrow-path" wire:click="refreshTools">{{ __('Refresh tools') }}</flux:button>
            </div>

            <dl class="mt-4 divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                    <dt><flux:text>{{ __('Status') }}</flux:text></dt>
                    <dd class="sm:col-span-2"><x-connection-status :status="$connection->status" /></dd>
                </div>

                <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                    <dt><flux:text>{{ __('Server URL') }}</flux:text></dt>
                    <dd class="min-w-0 sm:col-span-2"><flux:text variant="strong" class="break-all font-mono">{{ $connection->url }}</flux:text></dd>
                </div>

                <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                    <dt><flux:text>{{ __('Sign-in') }}</flux:text></dt>
                    <dd class="min-w-0 sm:col-span-2">
                        <flux:text variant="strong">
                            @if ($connection->usesConnectorToken())
                                {{ App\Enums\SignInMethod::Token->label($this->connector->name) }}
                            @elseif ($connection->usesOAuth() && $this->connector !== null)
                                {{ App\Enums\SignInMethod::OAuth->label($this->connector->name, ownApp: $connection->oauthClientId() !== null) }}
                            @else
                                {{ $connection->auth_type->label() }}
                            @endif

                            @if ($connection->auth_type === App\Enums\ConnectionAuthType::Header)
                                · <span class="font-mono">{{ $connection->headerName() }}</span>
                            @elseif ($connection->oauthClientId() !== null)
                                · {{ __('client ID') }} <span class="font-mono">{{ $connection->oauthClientId() }}</span>
                            @endif
                        </flux:text>
                    </dd>
                </div>

                <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                    <dt><flux:text>{{ __('Tools') }}</flux:text></dt>
                    <dd class="sm:col-span-2">
                        <flux:link :href="route('connections.tools', $connection)" wire:navigate>{{ trans_choice(':count tool|:count tools', $this->toolCount) }}</flux:link>
                    </dd>
                </div>

                <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                    <dt><flux:text>{{ __('Last refreshed') }}</flux:text></dt>
                    <dd class="sm:col-span-2">
                        <flux:text variant="strong">
                            @if ($connection->catalog_refreshed_at === null)
                                {{ __('Never') }}
                            @else
                                <time datetime="{{ $connection->catalog_refreshed_at->toIso8601String() }}" title="{{ $connection->catalog_refreshed_at->toDayDateTimeString() }}">{{ $connection->catalog_refreshed_at->diffForHumans() }}</time>
                            @endif
                        </flux:text>
                    </dd>
                </div>
            </dl>
        </section>

        <section aria-labelledby="details-heading">
            <flux:heading size="lg" level="2" id="details-heading">{{ __('Details') }}</flux:heading>
            <flux:text class="mt-1">{{ __('How this Connection is named in Nexus and described to agents. Its handle, :handle, never changes.', ['handle' => $connection->handle]) }}</flux:text>

            <form wire:submit="saveDetails" class="mt-6 max-w-xl space-y-6">
                <flux:input wire:model="name" :label="__('Name')" maxlength="100" />
                <flux:input wire:model="description" :label="__('Use this account for')" :badge="__('Optional')" :description="__('Helps agents pick the right account when you connect a service more than once.')" maxlength="200" />

                <flux:button type="submit">{{ __('Save details') }}</flux:button>
            </form>
        </section>

        @if ($connection->usesConnectorToken())
            <section aria-labelledby="token-heading">
                <flux:heading size="lg" level="2" id="token-heading">{{ __('Token') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Replace the token Nexus signs in to :name with, for example when it expires. Nexus reloads the tools when you save.', ['name' => $this->connector->name]) }}</flux:text>

                <form wire:submit="replaceToken" class="mt-6 max-w-xl space-y-6">
                    <flux:input
                        wire:model="token"
                        type="password"
                        viewable
                        :label="__('New token')"
                        :description="__('Stored encrypted. The current token is never shown.')"
                        autocomplete="off"
                    />

                    <div class="flex flex-wrap items-center gap-4">
                        <flux:button type="submit">{{ __('Replace token and reload tools') }}</flux:button>
                        <flux:link :href="$this->connector->token->consoleUrl" external rel="noopener noreferrer" class="text-sm">{{ __('Create a token on :name', ['name' => $this->connector->name]) }} &nearr;</flux:link>
                    </div>
                </form>
            </section>
        @elseif ($this->connector === null)
            <section aria-labelledby="server-heading">
                <flux:heading size="lg" level="2" id="server-heading">{{ __('Server and sign-in') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Nexus reloads the tools when you save. Changing the URL clears every stored credential, so enter the header value or client secret again, and sign in again.') }}</flux:text>

                <form wire:submit="saveServer" class="mt-6 max-w-xl space-y-6">
                    <flux:input wire:model="url" type="url" :label="__('Server URL')" autocomplete="off" />

                    <flux:radio.group wire:model.live="authType" :label="__('Sign-in')" variant="cards" class="max-sm:flex-col">
                        <flux:radio value="none" :label="__('No auth')" :description="__('The server needs no sign-in.')" />
                        <flux:radio value="header" :label="__('Header')" :description="__('Nexus sends a header, such as an API key, with every request.')" />
                        <flux:radio value="oauth" :label="__('OAuth')" :description="__('You approve Nexus on the server\'s own sign-in page.')" />
                    </flux:radio.group>

                    @if ($authType === 'header')
                        <flux:input wire:model="headerName" :label="__('Header name')" placeholder="Authorization" autocomplete="off" autocapitalize="off" spellcheck="false" />

                        <flux:input
                            wire:model="headerValue"
                            type="password"
                            viewable
                            :label="__('New header value')"
                            :description="$this->hasStoredHeaderValue
                                ? __('Stored encrypted. Leave blank to keep the current value.')
                                : __('Stored encrypted. Include any prefix the server expects, such as Bearer.')"
                            placeholder="Bearer …"
                            autocomplete="off"
                        />
                    @endif

                    @if ($authType === 'oauth')
                        <x-own-oauth-app :callback-url="$this->callbackUrl" :has-stored-secret="$this->hasStoredClientSecret" />
                    @endif

                    <flux:button type="submit">{{ $authType === 'oauth' && ! $connection->hasAccessToken() ? __('Save and sign in') : __('Save and reload tools') }}</flux:button>
                </form>
            </section>
        @endif

        <section aria-labelledby="delete-heading">
            <flux:heading size="lg" level="2" id="delete-heading">{{ __('Delete connection') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Removes this Connection, its stored credentials and its tools from Nexus.') }}</flux:text>

            <flux:modal.trigger name="delete-connection">
                <flux:button variant="danger" class="mt-4">{{ __('Delete connection') }}</flux:button>
            </flux:modal.trigger>
        </section>
    </div>

    <flux:modal name="delete-connection" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete :name?', ['name' => $connection->name]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('Its stored credentials and its tools are removed, and agents can no longer use them. This can\'t be undone.') }}</flux:text>

                @if ($this->stars->isNotEmpty())
                    <flux:text class="mt-4">{{ trans_choice('It is removed from this Star:|It is removed from these Stars:', $this->stars->count()) }}</flux:text>

                    <ul class="mt-2 list-disc space-y-1 ps-5">
                        @foreach ($this->stars as $star)
                            <li wire:key="delete-star-{{ $star->id }}"><flux:text variant="strong">{{ $star->name }}</flux:text></li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="delete">{{ __('Delete connection') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
