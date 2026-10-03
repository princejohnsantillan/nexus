<div class="mx-auto w-full max-w-5xl">
    <x-connection-header :connection="$connection" current="overview" />

    @if ($this->needsOAuthSignIn)
        <flux:callout icon="key" :color="$connection->status->color()" :heading="__('Sign in to use this Connection')" class="mt-8">
            <flux:callout.text>{{ $connection->last_error ?? __('Nexus needs you to approve it on the server\'s own sign-in page before it can load the tools.') }}</flux:callout.text>

            <x-slot name="actions">
                <flux:button :href="route('connections.connect', $connection)" variant="primary" size="sm">{{ __('Reconnect') }}</flux:button>
            </x-slot>
        </flux:callout>
    @elseif (filled($connection->last_error))
        <flux:callout icon="exclamation-triangle" :color="$connection->status->color()" :heading="__('Nexus couldn\'t load this Connection\'s tools')" class="mt-8">
            <flux:callout.text>{{ $connection->last_error }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="mt-8 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
        <div class="space-y-6">
            <x-section-card :heading="__('Status')" :description="__('Where Nexus reaches the server, how it signs in, and what it found at the last refresh.')">
                <dl class="divide-y divide-zinc-200 dark:divide-white/10">
                    <div class="grid gap-1 pb-3 sm:grid-cols-3 sm:gap-4">
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
                                    · {{ __('client ID') }} <span class="break-all font-mono">{{ $connection->oauthClientId() }}</span>
                                @endif
                            </flux:text>
                        </dd>
                    </div>

                    <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                        <dt><flux:text>{{ __('Account') }}</flux:text></dt>
                        <dd class="min-w-0 sm:col-span-2">
                            @if (filled($connection->account_identity))
                                <flux:text variant="strong" class="break-all">{{ $connection->account_identity }}</flux:text>
                            @else
                                <flux:text>{{ __('Not detected. Nexus names the account when the server says which one it is.') }}</flux:text>
                            @endif
                        </dd>
                    </div>

                    <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                        <dt><flux:text>{{ __('Tools') }}</flux:text></dt>
                        <dd class="sm:col-span-2">
                            <flux:link :href="route('connections.tools', $connection)" wire:navigate class="text-sm">{{ trans_choice(':count tool|:count tools', $this->toolCount) }}</flux:link>
                        </dd>
                    </div>

                    <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                        <dt><flux:text>{{ __('Prompts') }}</flux:text></dt>
                        <dd class="sm:col-span-2">
                            <flux:link :href="route('connections.prompts', $connection)" wire:navigate class="text-sm">{{ trans_choice(':count prompt|:count prompts', $this->promptCount) }}</flux:link>
                        </dd>
                    </div>

                    <div class="grid gap-1 pt-3 sm:grid-cols-3 sm:gap-4">
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

                <x-slot:hint>
                    @if ($this->sameServiceConnections->isNotEmpty())
                        {{ trans_choice(
                            'Refresh tools reloads only this account. :names is another account of the same service, with its own Refresh tools.|Refresh tools reloads only this account. :names are other accounts of the same service, each with its own Refresh tools.',
                            $this->sameServiceConnections->count(),
                            ['names' => $this->sameServiceNames],
                        ) }}
                    @else
                        {{ __('Nexus also refreshes the tools by itself every day.') }}
                    @endif
                </x-slot:hint>

                <x-slot:actions>
                    <flux:button icon="arrow-path" size="sm" wire:click="refreshTools">{{ __('Refresh tools') }}</flux:button>
                </x-slot:actions>
            </x-section-card>

            @if ($connection->usesConnectorToken())
                <x-section-card as="form" wire:submit="replaceToken" :heading="__('Token')" :description="__('Replace the token Nexus signs in to :name with, for example when it expires. Nexus reloads the tools when you save.', ['name' => $this->connector->name])">
                    <div class="space-y-6">
                        <flux:input
                            wire:model="token"
                            type="password"
                            viewable
                            :label="__('New token')"
                            :description="__('Stored encrypted. The current token is never shown.')"
                            autocomplete="off"
                        />

                        <flux:error name="server" />
                    </div>

                    <x-slot:hint>
                        <flux:link :href="$this->connector->token->consoleUrl" external rel="noopener noreferrer">{{ __('Create a token on :name', ['name' => $this->connector->name]) }} &nearr;</flux:link>
                    </x-slot:hint>

                    <x-slot:actions>
                        <flux:button type="submit" variant="primary" size="sm">{{ __('Replace token and reload tools') }}</flux:button>
                    </x-slot:actions>
                </x-section-card>
            @elseif ($this->connector === null)
                <x-section-card as="form" wire:submit="saveServer" :heading="__('Server and sign-in')" :description="__('Nexus reloads the tools when you save.')">
                    <div class="space-y-6">
                        <flux:input wire:model="url" type="url" :label="__('Server URL')" autocomplete="off" class:input="font-mono" />

                        <flux:radio.group wire:model.live="authType" :label="__('Sign-in')" variant="cards" class="max-sm:flex-col">
                            <flux:radio value="none" :label="__('No auth')" :description="__('The server needs no sign-in.')" />
                            <flux:radio value="header" :label="__('Header')" :description="__('Nexus sends a header, such as an API key, with every request.')" />
                            <flux:radio value="oauth" :label="__('OAuth')" :description="__('You approve Nexus on the server\'s own sign-in page.')" />
                        </flux:radio.group>

                        @if ($authType === 'header')
                            <flux:input wire:model="headerName" :label="__('Header name')" placeholder="Authorization" autocomplete="off" autocapitalize="off" spellcheck="false" class:input="font-mono" />

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

                        <flux:error name="server" />
                    </div>

                    <x-slot:hint>{{ __('Changing the URL clears every stored credential, so enter the header value or client secret again, and sign in again.') }}</x-slot:hint>

                    <x-slot:actions>
                        <flux:button type="submit" variant="primary" size="sm">{{ $authType === 'oauth' && ! $connection->hasAccessToken() ? __('Save and sign in') : __('Save and reload tools') }}</flux:button>
                    </x-slot:actions>
                </x-section-card>
            @endif
        </div>

        <div class="space-y-6">
            <x-section-card :heading="__('Used in Stars')" :description="__('Agents reach this Connection\'s tools through the Stars that include it.')">
                @if ($this->stars->isEmpty())
                    <x-empty-state icon="star" :heading="__('Not in a Star yet')">
                        {{ __('Add it to a Star so agents can use its tools.') }}
                    </x-empty-state>
                @else
                    <ul class="divide-y divide-zinc-200 dark:divide-white/10" data-used-in-stars>
                        @foreach ($this->stars as $star)
                            <li wire:key="used-in-star-{{ $star->id }}" class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-700" aria-hidden="true">
                                        <flux:icon.star variant="micro" class="text-zinc-500 dark:text-zinc-300" />
                                    </div>

                                    <div class="min-w-0 truncate text-sm">
                                        <flux:link :href="route('stars.show', $star)" :accent="false" variant="ghost" wire:navigate>{{ $star->name }}</flux:link>
                                    </div>
                                </div>

                                @if ($this->toolCount > 0)
                                    <flux:link :href="route('stars.tools', $star)" variant="subtle" wire:navigate class="shrink-0 text-sm whitespace-nowrap">
                                        {{ trans_choice(':enabled of :count tool on|:enabled of :count tools on', $this->toolCount, ['enabled' => $this->enabledToolCounts[$star->id] ?? 0]) }}
                                    </flux:link>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                <x-slot:hint>
                    @if ($this->otherStars->isNotEmpty())
                        {{ __('Its tools start from each Star\'s new-tool policy.') }}
                    @elseif ($this->stars->isEmpty())
                        {{ __('You have no Stars yet.') }}
                    @else
                        {{ __('Every one of your Stars includes it.') }}
                    @endif
                </x-slot:hint>

                <x-slot:actions>
                    @if ($this->otherStars->isNotEmpty())
                        <flux:dropdown position="bottom" align="end">
                            <flux:button icon="plus" icon:trailing="chevron-down" size="sm">{{ __('Add to a Star') }}</flux:button>

                            <flux:menu>
                                @foreach ($this->otherStars as $star)
                                    <flux:menu.item icon="star" wire:click="addToStar({{ $star->id }})" wire:key="add-to-star-{{ $star->id }}">{{ $star->name }}</flux:menu.item>
                                @endforeach
                            </flux:menu>
                        </flux:dropdown>
                    @elseif ($this->stars->isEmpty())
                        <flux:button icon="plus" size="sm" :href="route('stars.index')" wire:navigate>{{ __('Create a Star') }}</flux:button>
                    @endif
                </x-slot:actions>
            </x-section-card>

            <x-section-card as="form" wire:submit="saveDetails" :heading="__('Details')" :description="__('How this Connection is named in Nexus and described to agents.')">
                <div class="space-y-6">
                    <flux:input wire:model="name" :label="__('Name')" maxlength="100" />
                    <flux:input wire:model="description" :label="__('Use this account for')" :badge="__('Optional')" :description="__('Helps agents pick the right account when you connect a service more than once.')" maxlength="200" />
                </div>

                <x-slot:hint><span class="font-mono">{{ $connection->handle }}</span> · {{ __('The handle never changes.') }}</x-slot:hint>

                <x-slot:actions>
                    <flux:button type="submit" variant="primary" size="sm">{{ __('Save details') }}</flux:button>
                </x-slot:actions>
            </x-section-card>

            <x-danger-card :heading="__('Delete connection')" :description="__('Removes this Connection, its stored credentials and its tools from Nexus, and takes it out of every Star that includes it.')">
                <x-slot:actions>
                    <flux:modal.trigger name="delete-connection">
                        <flux:button variant="danger" size="sm">{{ __('Delete connection') }}</flux:button>
                    </flux:modal.trigger>
                </x-slot:actions>
            </x-danger-card>
        </div>
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
