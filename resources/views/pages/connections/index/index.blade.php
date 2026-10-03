<div class="mx-auto w-full max-w-5xl">
    @php
        $column = 'px-5! py-2.5! text-xs! font-medium text-zinc-600! dark:text-zinc-300! border-zinc-200! dark:border-white/10!';
        $cell = 'px-5! border-zinc-200! dark:border-white/10!';
    @endphp

    <x-page-header :heading="__('Connections')" :description="__('Each Connection is one of your accounts on a remote MCP server. Connect it once, use it in any Star.')">
        <x-slot:aside class="gap-4">
            <span @class([
                'text-sm tabular-nums',
                'text-zinc-500 dark:text-zinc-400' => ! $this->isAtConnectionLimit,
                'font-medium text-warning' => $this->isAtConnectionLimit,
            ]) data-connection-count>
                @if ($this->limit === null)
                    {{ trans_choice(':count Connection|:count Connections', $this->connections->count()) }}
                @else
                    <span class="sr-only">{{ __('Connections used:') }}</span> {{ __(':count / :limit', ['count' => $this->connections->count(), 'limit' => $this->limit]) }}
                @endif
            </span>

            @if ($this->connections->isNotEmpty())
                @if ($this->isAtConnectionLimit)
                    <flux:modal.trigger name="connection-limit">
                        <flux:button variant="primary" icon="plus" data-add-connection>{{ __('Add connection') }}</flux:button>
                    </flux:modal.trigger>
                @else
                    <flux:button variant="primary" icon="plus" href="#add-more" data-add-connection>{{ __('Add connection') }}</flux:button>
                @endif
            @endif
        </x-slot:aside>
    </x-page-header>

    @if ($this->connections->isEmpty())
        <x-empty-state icon="link" :heading="__('No Connections yet')" class="mt-8">
            {{ __('Connect GitHub, Notion, Linear or any remote MCP server once, then use it in as many Stars as you like.') }}

            <x-slot:actions>
                @if ($this->isAtConnectionLimit)
                    <flux:modal.trigger name="connection-limit">
                        <flux:button variant="primary" icon="arrow-down">{{ __('Add your first connection') }}</flux:button>
                    </flux:modal.trigger>
                @else
                    <flux:button variant="primary" icon="arrow-down" href="#add-more">{{ __('Add your first connection') }}</flux:button>
                @endif
            </x-slot:actions>
        </x-empty-state>
    @else
        <section class="mt-10" aria-labelledby="connected-heading">
            <div class="flex items-baseline gap-2">
                <flux:heading size="lg" level="2" id="connected-heading">{{ __('Connected') }}</flux:heading>
                <span class="text-sm tabular-nums text-zinc-500 dark:text-zinc-400">{{ $this->connections->count() }}</span>
            </div>

            <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-white/[4%]">
                <flux:table>
                    <flux:table.columns class="bg-zinc-50 dark:bg-black/15">
                        <flux:table.column :class="$column">{{ __('Account') }}</flux:table.column>
                        <flux:table.column :class="$column">{{ __('Handle') }}</flux:table.column>
                        <flux:table.column :class="$column">{{ __('Tools') }}</flux:table.column>
                        <flux:table.column :class="$column">{{ __('Used in') }}</flux:table.column>
                        <flux:table.column :class="$column">{{ __('Status') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->connections as $connection)
                            @php
                                $connector = $connection->connector();
                                $problem = App\Stars\ConnectionProblems::of($connection);
                                // Adding to a Star, signing in a Connection that is in no Star yet (such as one whose first sign-in was refused) adds it there too.
                                $fixUrl = $problem?->canReconnect() && $this->returnStar !== null && $connection->stars->isEmpty()
                                    ? route('connections.connect', ['connection' => $connection, ...App\Stars\ReturnToStar::query($this->returnStar)])
                                    : $problem?->fixUrl();
                                $tint = match ($problem?->tone()) {
                                    'warning' => 'bg-warning-wash',
                                    'danger' => 'bg-danger-wash',
                                    default => '',
                                };
                            @endphp

                            <flux:table.row :key="$connection->id" :class="$tint" data-connection="{{ $connection->handle }}">
                                <flux:table.cell :class="$cell">
                                    <div class="flex items-center gap-3">
                                        <x-connector-logo :connector="$connector" size="sm" />

                                        <div class="min-w-0">
                                            <a href="{{ route('connections.show', $connection) }}" class="block max-w-52 truncate font-medium text-zinc-950 hover:underline dark:text-white" wire:navigate>{{ $connection->name }}</a>

                                            @if ($connector === null || filled($connection->account_identity) || filled($connection->description))
                                                <div class="mt-0.5 max-w-52 truncate text-sm text-zinc-500 dark:text-zinc-400">
                                                    @if ($connector === null)
                                                        {{ __('Custom server · :host', ['host' => parse_url($connection->url, PHP_URL_HOST) ?: $connection->url]) }}
                                                    @endif
                                                    <x-account-label :connection="$connection" :separated="$connector === null" />
                                                </div>
                                            @endif

                                            @if ($problem !== null)
                                                <div @class([
                                                    'mt-0.5 max-w-52 text-sm whitespace-normal',
                                                    'text-warning' => $problem->tone() === 'warning',
                                                    'text-danger' => $problem->tone() === 'danger',
                                                ]) data-connection-problem>{{ $problem->reason() }}</div>
                                            @endif

                                            @if ($problem?->canReconnect())
                                                <flux:button size="sm" variant="primary" color="zinc" :href="$fixUrl" class="mt-2 md:hidden">{{ $problem->fixLabel() }}</flux:button>
                                            @endif
                                        </div>
                                    </div>
                                </flux:table.cell>

                                <flux:table.cell :class="$cell">
                                    <span class="font-mono text-zinc-950 dark:text-white">{{ $connection->handle }}</span>
                                </flux:table.cell>

                                <flux:table.cell :class="$cell">
                                    <span class="text-zinc-950 tabular-nums dark:text-white">{{ $connection->tools_count }}</span>
                                </flux:table.cell>

                                <flux:table.cell :class="$cell">
                                    @if ($connection->stars->isEmpty())
                                        <span class="text-zinc-500 dark:text-zinc-400" title="{{ __('Not in any Star') }}">&mdash;<span class="sr-only">{{ __('Not in any Star') }}</span></span>
                                    @else
                                        <div class="flex items-center gap-1.5">
                                            @foreach ($connection->stars->take(2) as $star)
                                                <a
                                                    href="{{ route('stars.show', $star) }}"
                                                    class="inline-flex h-6 max-w-24 items-center rounded-md border border-zinc-200 bg-white px-2 text-xs font-medium text-zinc-800 hover:border-zinc-300 hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:text-zinc-200 dark:hover:bg-white/10"
                                                    wire:navigate
                                                    wire:key="connection-{{ $connection->id }}-star-{{ $star->id }}"
                                                ><span class="truncate">{{ $star->name }}</span></a>
                                            @endforeach

                                            @if ($connection->stars->count() > 2)
                                                <flux:dropdown position="bottom" align="start">
                                                    <flux:button size="xs" variant="ghost" :aria-label="trans_choice('Show :count more Star|Show :count more Stars', $connection->stars->count() - 2)">{{ __('+:count', ['count' => $connection->stars->count() - 2]) }}</flux:button>

                                                    <flux:menu>
                                                        @foreach ($connection->stars->skip(2) as $star)
                                                            <flux:menu.item icon="star" :href="route('stars.show', $star)" wire:navigate wire:key="connection-{{ $connection->id }}-more-star-{{ $star->id }}">{{ $star->name }}</flux:menu.item>
                                                        @endforeach
                                                    </flux:menu>
                                                </flux:dropdown>
                                            @endif
                                        </div>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell :class="$cell">
                                    <div class="flex items-center justify-between gap-3">
                                        <x-connection-status :status="$connection->status" />

                                        <div class="flex items-center gap-1.5">
                                            <flux:icon.loading wire:loading wire:target="refreshTools({{ $connection->id }})" class="size-4 text-zinc-500 dark:text-zinc-400" />

                                            @if ($problem?->canReconnect())
                                                <flux:button size="sm" variant="primary" color="zinc" :href="$fixUrl" class="max-md:hidden">{{ $problem->fixLabel() }}</flux:button>
                                            @endif

                                            <flux:dropdown position="bottom" align="end">
                                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More for :name', ['name' => $connection->name])" />

                                                <flux:menu>
                                                    <flux:menu.item icon="arrow-right" :href="route('connections.show', $connection)" wire:navigate>{{ __('Open') }}</flux:menu.item>
                                                    <flux:menu.item icon="arrow-path" wire:click="refreshTools({{ $connection->id }})">{{ __('Refresh tools') }}</flux:menu.item>

                                                    <flux:menu.separator />

                                                    <flux:menu.heading>
                                                        @if ($connection->catalog_refreshed_at === null)
                                                            {{ __('Tools never loaded') }}
                                                        @else
                                                            {{ __('Tools refreshed') }} <time datetime="{{ $connection->catalog_refreshed_at->toIso8601String() }}" title="{{ $connection->catalog_refreshed_at->toDayDateTimeString() }}">{{ $connection->catalog_refreshed_at->diffForHumans() }}</time>
                                                        @endif
                                                    </flux:menu.heading>
                                                </flux:menu>
                                            </flux:dropdown>
                                        </div>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </section>
    @endif

    <section id="add-more" class="mt-12 scroll-mt-6" aria-labelledby="add-more-heading">
        @if ($this->returnStar !== null)
            <x-adding-to-star :star="$this->returnStar" cancel class="mb-6 rounded-lg border border-accent/20 px-4 py-3" />
        @endif

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <flux:heading size="lg" level="2" id="add-more-heading">{{ $this->connections->isEmpty() ? __('Connect a server') : __('Add more') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ $this->connections->isEmpty()
                        ? __('Choose the MCP server to connect. Nexus signs in to it once, and every Star you add it to can use its tools.')
                        : __('Connecting a service you already use adds another account.') }}
                </flux:text>
            </div>

            <div class="w-full sm:w-72">
                <flux:input wire:model.live.debounce.250ms="search" type="search" icon="magnifying-glass" :placeholder="__('Search servers')" :aria-label="__('Search servers')" autocomplete="off" />
            </div>
        </div>

        @if ($this->catalog === [])
            <x-empty-state icon="magnifying-glass" :heading="__('No server in the catalog matches “:search”', ['search' => trim($search)])" class="mt-4">
                {{ __('Try another name, or connect it by its URL as a custom server.') }}

                <x-slot:actions>
                    <flux:button wire:click="$set('search', '')">{{ __('Clear search') }}</flux:button>

                    @if ($this->isAtConnectionLimit)
                        <flux:modal.trigger name="connection-limit">
                            <flux:button variant="primary" data-connect-by-url>{{ __('Connect by URL') }}</flux:button>
                        </flux:modal.trigger>
                    @else
                        <flux:button variant="primary" :href="route('connections.add-custom', App\Stars\ReturnToStar::query($this->returnStar))" wire:navigate data-connect-by-url>{{ __('Connect by URL') }}</flux:button>
                    @endif
                </x-slot:actions>
            </x-empty-state>
        @else
            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($this->catalog as $connector)
                    @php
                        $connected = $this->connectedCounts[$connector->key] ?? 0;
                    @endphp

                    <flux:card class="flex flex-col p-5!" wire:key="connector-{{ $connector->key }}" data-connector="{{ $connector->key }}">
                        <div class="flex items-start justify-between gap-3">
                            <x-connector-logo :connector="$connector" />

                            <div class="flex flex-wrap justify-end gap-1">
                                @if ($connected > 0)
                                    <span class="inline-flex h-6 items-center gap-1.5 rounded-full bg-success-wash px-2.5 text-xs font-medium whitespace-nowrap text-success" data-connected-count>
                                        <span class="size-1.5 rounded-full bg-current"></span>
                                        {{ trans_choice(':count connected|:count connected', $connected) }}
                                    </span>
                                @endif

                                @if ($connector->preview)
                                    <flux:badge size="sm" color="sky">{{ __('Preview') }}</flux:badge>
                                @endif

                                @unless ($connector->isAvailable())
                                    <flux:badge size="sm" color="zinc">{{ __('Not available yet') }}</flux:badge>
                                @endunless
                            </div>
                        </div>

                        <div class="mt-4 flex items-baseline justify-between gap-3">
                            <flux:heading size="lg" level="3" class="min-w-0 truncate">{{ $connector->name }}</flux:heading>
                            <flux:link :href="$connector->docsUrl" external rel="noopener noreferrer" variant="subtle" class="shrink-0 text-sm whitespace-nowrap">{{ __('Docs') }} &nearr;</flux:link>
                        </div>
                        <flux:text class="mt-1 flex-1">{{ $connector->summary }}</flux:text>

                        @if ($connector->unavailableReason() !== null)
                            <flux:text size="sm" class="mt-3">{{ $connector->unavailableReason() }}</flux:text>
                        @endif

                        <div class="mt-5">
                            @if (! $connector->isAvailable())
                                <flux:button size="sm" disabled>{{ $connected > 0 ? __('Add another account') : __('Connect') }}</flux:button>
                            @elseif ($this->isAtConnectionLimit)
                                <flux:modal.trigger name="connection-limit">
                                    <flux:button size="sm">{{ $connected > 0 ? __('Add another account') : __('Connect') }}</flux:button>
                                </flux:modal.trigger>
                            @else
                                <flux:button size="sm" wire:click="startConnecting('{{ $connector->key }}')">{{ $connected > 0 ? __('Add another account') : __('Connect') }}</flux:button>
                            @endif
                        </div>
                    </flux:card>
                @endforeach

                <flux:card class="flex flex-col p-5!" data-connector="custom">
                    <x-connector-logo />

                    <flux:heading size="lg" level="3" class="mt-4">{{ __('Custom server') }}</flux:heading>
                    <flux:text class="mt-1 flex-1">{{ __('Any remote MCP server by URL: no sign-in, a header, or OAuth.') }}</flux:text>

                    <div class="mt-5">
                        @if ($this->isAtConnectionLimit)
                            <flux:modal.trigger name="connection-limit">
                                <flux:button size="sm">{{ __('Connect by URL') }}</flux:button>
                            </flux:modal.trigger>
                        @else
                            <flux:button size="sm" :href="route('connections.add-custom', App\Stars\ReturnToStar::query($this->returnStar))" wire:navigate>{{ __('Connect by URL') }}</flux:button>
                        @endif
                    </div>
                </flux:card>
            </div>
        @endif

        <flux:text size="sm" class="mt-8 text-zinc-500 dark:text-zinc-400">{{ $this->attribution }}</flux:text>
    </section>

    <flux:modal name="connect" class="w-full max-w-xl">
        @if ($this->connector !== null)
            <form wire:submit="connect">
                <div class="flex items-center gap-3 pe-8">
                    <x-connector-logo :connector="$this->connector" size="sm" />
                    <flux:heading size="lg">{{ __('Connect :name', ['name' => $this->connector->name]) }}</flux:heading>
                </div>

                @if ($this->returnStar !== null)
                    <x-adding-to-star :star="$this->returnStar" class="-mx-6 mt-5 border-y border-accent/15 px-6 py-2.5" />
                @endif

                <div class="mt-5 space-y-6">
                    <flux:text>{{ __('Already connected :name? Connecting it again adds another account.', ['name' => $this->connector->name]) }}</flux:text>

                    <div class="space-y-3">
                        <div class="grid gap-x-4 gap-y-6 sm:grid-cols-2">
                            <flux:input wire:model="name" :label="__('Name')" maxlength="100" />

                            <flux:input
                                wire:model="handle"
                                :label="__('Handle')"
                                :maxlength="\App\Models\Connection::HANDLE_MAX_LENGTH"
                                class:input="font-mono"
                                autocomplete="off"
                                autocapitalize="off"
                                spellcheck="false"
                            />
                        </div>

                        <x-handle-preview :handle="$handle" :tool="$this->exampleTool" />
                    </div>

                    <flux:input wire:model="description" :label="__('Use this account for')" :badge="__('Optional')" :description="__('Helps agents pick the right account when you connect a service more than once, e.g. \'work repositories\'.')" maxlength="200" />

                    @if (count($this->connector->methods()) > 1)
                        <flux:radio.group wire:model.live="method" :label="__('Sign in with')" variant="cards" class="max-sm:flex-col">
                            @foreach ($this->connector->methods() as $option)
                                <flux:radio
                                    :value="$option->value"
                                    :label="$option->label($this->connector->name, ownApp: $this->connector->needsUserApp())"
                                    :description="$this->connector->whyUnavailable($option) === null
                                        ? $option->description($this->connector->name, ownApp: $this->connector->needsUserApp())
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

                    @if ($method === App\Enums\SignInMethod::OAuth->value)
                        @if ($this->connector->needsUserApp() && $this->connector->app !== null)
                            <div class="space-y-3">
                                <flux:text>{{ $this->connector->app->instructions }}</flux:text>

                                <flux:link :href="$this->connector->app->consoleUrl" external rel="noopener noreferrer" class="text-sm">{{ __('Register an OAuth app on :name', ['name' => $this->connector->name]) }} &nearr;</flux:link>
                            </div>

                            <flux:input :value="$this->callbackUrl" readonly copyable :label="__('Callback URL')" :description="__('Enter this as the app\'s callback URL.')" class:input="font-mono" />

                            <flux:input wire:model="clientId" :label="__('Client ID')" autocomplete="off" autocapitalize="off" spellcheck="false" class:input="font-mono" />

                            <flux:input
                                wire:model="clientSecret"
                                type="password"
                                viewable
                                :label="__('Client secret')"
                                :description="__('Stored encrypted.')"
                                autocomplete="off"
                            />
                        @else
                            <flux:text>
                                {{ $this->returnStar === null
                                    ? __('Nexus sends you to :name to approve access, then brings you back here and loads the tools.', ['name' => $this->connector->name])
                                    : __('Nexus sends you to :name to approve access, then loads the tools and brings you back to :star.', ['name' => $this->connector->name, 'star' => $this->returnStar->name]) }}
                            </flux:text>
                        @endif
                    @endif

                    <flux:error name="limit" />
                </div>

                <div class="-mx-6 mt-6 -mb-6 flex flex-wrap items-center justify-end gap-x-4 gap-y-3 rounded-b-xl border-t border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-white/10 dark:bg-black/15">
                    @if ($this->returnStar !== null)
                        <flux:text class="me-auto min-w-0 wrap-anywhere" data-starts-on>{{ $this->returnStar->new_tool_policy->startsOnIn($this->returnStar->name) }}</flux:text>
                    @endif

                    <div class="flex gap-2">
                        @if ($this->returnStar !== null)
                            <flux:button variant="ghost" :href="route('stars.show', $this->returnStar)" wire:navigate data-connect-cancel>{{ __('Cancel') }}</flux:button>
                        @else
                            <flux:modal.close>
                                <flux:button variant="ghost" data-connect-cancel>{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>
                        @endif

                        @if ($method === App\Enums\SignInMethod::OAuth->value)
                            <flux:button type="submit" variant="primary" icon:trailing="arrow-up-right">{{ __('Continue to :name', ['name' => $this->connector->name]) }}</flux:button>
                        @else
                            <flux:button type="submit" variant="primary">{{ __('Connect and load tools') }}</flux:button>
                        @endif
                    </div>
                </div>
            </form>
        @endif
    </flux:modal>

    @if ($this->isAtConnectionLimit)
        <x-limit-modal for="connections" />
    @endif
</div>
