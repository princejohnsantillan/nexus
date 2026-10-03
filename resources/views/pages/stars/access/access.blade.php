<div class="mx-auto w-full max-w-5xl">
    @php
        $column = 'px-5! py-2.5! text-xs! font-medium text-zinc-600! dark:text-zinc-300! border-zinc-200! dark:border-white/10!';
        $cell = 'px-5! py-3! border-zinc-200! dark:border-white/10!';
        $pill = 'inline-flex h-6 items-center rounded-md bg-zinc-50 px-2 font-mono text-xs text-zinc-700 ring-1 ring-zinc-200 ring-inset dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10';
    @endphp

    <x-star-header :star="$star" current="access" />

    <div class="mt-8 space-y-10">
        <x-section-card id="access-mode" :heading="__('Access mode')" :description="__('How clients authenticate to this Star.')">
            <flux:radio.group wire:model.live="accessMode" variant="cards" class="max-sm:flex-col" :aria-label="__('Access mode')">
                @foreach (App\Enums\StarAccessMode::cases() as $option)
                    <flux:radio :value="$option->value" :label="$option->label()" :description="$option->description()" wire:key="access-mode-{{ $option->value }}" />
                @endforeach
            </flux:radio.group>

            <flux:error name="accessMode" class="mt-2" />

            <x-slot:hint>{{ __('It accepts only its current mode, so switching stops clients using the old one.') }}</x-slot:hint>

            @if ($this->chosenMode !== null && $this->chosenMode !== $star->access_mode)
                <x-slot:actions>
                    <flux:modal.trigger name="change-access-mode">
                        <flux:button variant="primary" size="sm">{{ __('Switch to :mode', ['mode' => $this->chosenMode->label()]) }}</flux:button>
                    </flux:modal.trigger>
                </x-slot:actions>
            @endif
        </x-section-card>

        @if ($star->access_mode === App\Enums\StarAccessMode::SignedUrl)
            <x-section-card id="signed-url" :heading="__('Signed URL')" :description="__('Anyone who has this URL can use the Star, so treat it like a password.')">
                <x-code-panel :label="__('URL')" :code="$star->signedUrl()" />

                <x-slot:hint>{{ __('Rotating gives the Star a new URL and stops the old one working at once.') }}</x-slot:hint>
                <x-slot:actions>
                    <flux:modal.trigger name="rotate-signed-url">
                        <flux:button size="sm" icon="arrow-path">{{ __('Rotate URL') }}</flux:button>
                    </flux:modal.trigger>
                </x-slot:actions>
            </x-section-card>
        @elseif ($star->access_mode === App\Enums\StarAccessMode::OAuth)
            <section aria-labelledby="apps-heading">
                <flux:heading size="lg" level="2" id="apps-heading">{{ __('Connected apps') }}</flux:heading>
                <flux:text class="mt-1">{{ __('The clients you approved for this Star. A client signs in to Nexus by itself when you add the Star\'s URL to it, and you approve it on Nexus\'s consent screen. Revoking one stops it at once; it has to be approved again to come back.') }}</flux:text>

                @if ($this->connectedApps->isEmpty())
                    <x-empty-state icon="squares-plus" :heading="__('No connected apps yet')" class="mt-4">
                        {{ __('Add this Star\'s URL to a client, using the setup on the overview. When the client connects, it sends you to Nexus to approve it.') }}

                        <x-slot:actions>
                            <flux:button :href="route('stars.show', $star).'#setup'" wire:navigate>{{ __('Set up a client') }}</flux:button>
                        </x-slot:actions>
                    </x-empty-state>
                @else
                    <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-white/[4%]" data-connected-apps>
                        <flux:table>
                            <flux:table.columns class="bg-zinc-50 dark:bg-black/15">
                                <flux:table.column :class="$column">{{ __('App') }}</flux:table.column>
                                <flux:table.column :class="$column.' max-sm:hidden'">{{ __('Returns to') }}</flux:table.column>
                                <flux:table.column :class="$column.' max-sm:hidden'">{{ __('Approved') }}</flux:table.column>
                                <flux:table.column :class="$column.' max-sm:hidden'">{{ __('Last used') }}</flux:table.column>
                                <flux:table.column :class="$column.' w-0'"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($this->connectedApps as $app)
                                    @php
                                        $returnsTo = implode(', ', $app->redirectHosts());
                                    @endphp

                                    <flux:table.row :key="$app->id" data-connected-app="{{ $app->id }}">
                                        <flux:table.cell :class="$cell">
                                            <div class="max-w-56 truncate font-medium text-zinc-950 dark:text-white">{{ $app->client?->name }}</div>
                                            <div class="mt-0.5 max-w-56 truncate text-zinc-500 sm:hidden dark:text-zinc-400">
                                                <span class="font-mono text-xs">{{ $returnsTo }}</span> ·
                                                @if ($app->last_used_at !== null)
                                                    {{ __('Used') }} <time datetime="{{ $app->last_used_at->toIso8601String() }}" title="{{ $app->last_used_at->toDayDateTimeString() }}">{{ $app->last_used_at->diffForHumans() }}</time>
                                                @else
                                                    {{ __('Never used') }}
                                                @endif
                                            </div>
                                        </flux:table.cell>
                                        <flux:table.cell :class="$cell.' max-sm:hidden'">
                                            <span class="block max-w-56 truncate font-mono text-xs text-zinc-600 dark:text-zinc-300" title="{{ $returnsTo }}">{{ $returnsTo }}</span>
                                        </flux:table.cell>
                                        <flux:table.cell :class="$cell.' max-sm:hidden text-zinc-600! dark:text-zinc-300!'">
                                            @if ($app->approved_at !== null)
                                                <time datetime="{{ $app->approved_at->toIso8601String() }}" title="{{ $app->approved_at->toDayDateTimeString() }}">{{ $app->approved_at->diffForHumans() }}</time>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell :class="$cell.' max-sm:hidden text-zinc-600! dark:text-zinc-300!'">
                                            @if ($app->last_used_at !== null)
                                                <time datetime="{{ $app->last_used_at->toIso8601String() }}" title="{{ $app->last_used_at->toDayDateTimeString() }}">{{ $app->last_used_at->diffForHumans() }}</time>
                                            @else
                                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Never') }}</span>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell :class="$cell" align="end">
                                            <flux:dropdown position="bottom" align="end">
                                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More for :name', ['name' => $app->client?->name])" />

                                                <flux:menu>
                                                    <flux:modal.trigger :name="'revoke-app-'.$app->id">
                                                        <flux:menu.item variant="danger" icon="no-symbol">{{ __('Revoke') }}</flux:menu.item>
                                                    </flux:modal.trigger>
                                                </flux:menu>
                                            </flux:dropdown>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>

                    @foreach ($this->connectedApps as $app)
                        <flux:modal :name="'revoke-app-'.$app->id" class="w-full max-w-lg" wire:key="revoke-app-{{ $app->id }}">
                            <div class="space-y-6">
                                <div>
                                    <flux:heading size="lg" class="wrap-anywhere">{{ __('Revoke :name?', ['name' => $app->client?->name]) }}</flux:heading>
                                    <flux:text class="mt-2">{{ __('It stops reaching the Star at once, and can\'t renew its sign-in. To use it again, approve it again when it asks. This can\'t be undone.') }}</flux:text>
                                </div>

                                <div class="flex justify-end gap-2">
                                    <flux:modal.close>
                                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                    </flux:modal.close>

                                    <flux:button variant="danger" wire:click="revokeApp({{ $app->id }})">{{ __('Revoke app') }}</flux:button>
                                </div>
                            </div>
                        </flux:modal>
                    @endforeach
                @endif
            </section>
        @else
            <section aria-labelledby="tokens-heading">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div class="min-w-0 flex-1 basis-80">
                        <flux:heading size="lg" level="2" id="tokens-heading">{{ __('Tokens') }}</flux:heading>
                        <flux:text class="mt-1 wrap-anywhere">
                            {{ __('Clients reach this Star with one of its tokens, sent as "Authorization: Bearer nxs_…". Give each client its own token, so you can revoke one without the others. The setup on the overview reads it from :variable.', ['variable' => $this->tokenVariable]) }}
                        </flux:text>
                    </div>

                    @if ($this->limitMessage === null)
                        <flux:modal.trigger name="new-token">
                            <flux:button variant="primary" icon="plus" data-create-token>{{ __('Create token') }}</flux:button>
                        </flux:modal.trigger>
                    @else
                        <flux:button variant="primary" icon="plus" disabled data-create-token>{{ __('Create token') }}</flux:button>
                    @endif
                </div>

                @if ($this->limitMessage !== null)
                    <flux:callout icon="exclamation-triangle" color="amber" class="mt-4" :heading="__('Token limit reached')">
                        <flux:callout.text>{{ $this->limitMessage }}</flux:callout.text>
                    </flux:callout>
                @endif

                @if ($this->tokens->isEmpty())
                    <x-empty-state icon="key" :heading="__('No tokens yet')" class="mt-4">
                        {{ __('Create a token for each client you add this Star to. Nexus shows it once, so copy it then.') }}
                    </x-empty-state>
                @else
                    <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-white/[4%]" data-tokens>
                        <flux:table>
                            <flux:table.columns class="bg-zinc-50 dark:bg-black/15">
                                <flux:table.column :class="$column">{{ __('Name') }}</flux:table.column>
                                <flux:table.column :class="$column.' max-sm:hidden'">{{ __('Token') }}</flux:table.column>
                                <flux:table.column :class="$column.' max-sm:hidden'">{{ __('Created') }}</flux:table.column>
                                <flux:table.column :class="$column.' max-sm:hidden'">{{ __('Last used') }}</flux:table.column>
                                <flux:table.column :class="$column.' w-0'"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($this->tokens as $token)
                                    <flux:table.row :key="$token->id" data-token="{{ $token->id }}">
                                        <flux:table.cell :class="$cell">
                                            <div class="max-w-56 truncate font-medium text-zinc-950 sm:max-w-64 dark:text-white">{{ $token->name }}</div>
                                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-zinc-500 sm:hidden dark:text-zinc-400">
                                                <span class="{{ $pill }}">{{ $token->prefix }}…</span>
                                                @if ($token->last_used_at !== null)
                                                    <span>{{ __('Used') }} <time datetime="{{ $token->last_used_at->toIso8601String() }}" title="{{ $token->last_used_at->toDayDateTimeString() }}">{{ $token->last_used_at->diffForHumans() }}</time></span>
                                                @else
                                                    <span>{{ __('Never used') }}</span>
                                                @endif
                                            </div>
                                        </flux:table.cell>
                                        <flux:table.cell :class="$cell.' max-sm:hidden'">
                                            <span class="{{ $pill }}" data-token-prefix>{{ $token->prefix }}…</span>
                                        </flux:table.cell>
                                        <flux:table.cell :class="$cell.' max-sm:hidden text-zinc-600! dark:text-zinc-300!'">
                                            @if ($token->created_at !== null)
                                                <time datetime="{{ $token->created_at->toIso8601String() }}" title="{{ $token->created_at->toDayDateTimeString() }}">{{ $token->created_at->diffForHumans() }}</time>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell :class="$cell.' max-sm:hidden text-zinc-600! dark:text-zinc-300!'">
                                            @if ($token->last_used_at !== null)
                                                <time datetime="{{ $token->last_used_at->toIso8601String() }}" title="{{ $token->last_used_at->toDayDateTimeString() }}">{{ $token->last_used_at->diffForHumans() }}</time>
                                            @else
                                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Never') }}</span>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell :class="$cell" align="end">
                                            <flux:dropdown position="bottom" align="end">
                                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('More for :name', ['name' => $token->name])" />

                                                <flux:menu>
                                                    <flux:modal.trigger :name="'revoke-token-'.$token->id">
                                                        <flux:menu.item variant="danger" icon="no-symbol">{{ __('Revoke') }}</flux:menu.item>
                                                    </flux:modal.trigger>
                                                </flux:menu>
                                            </flux:dropdown>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>

                    @foreach ($this->tokens as $token)
                        <flux:modal :name="'revoke-token-'.$token->id" class="w-full max-w-lg" wire:key="revoke-token-{{ $token->id }}">
                            <div class="space-y-6">
                                <div>
                                    <flux:heading size="lg" class="wrap-anywhere">{{ __('Revoke :name?', ['name' => $token->name]) }}</flux:heading>
                                    <flux:text class="mt-2">{{ __('Clients using this token stop reaching the Star at once. This can\'t be undone.') }}</flux:text>
                                </div>

                                <div class="flex justify-end gap-2">
                                    <flux:modal.close>
                                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                    </flux:modal.close>

                                    <flux:button variant="danger" wire:click="revoke({{ $token->id }})">{{ __('Revoke token') }}</flux:button>
                                </div>
                            </div>
                        </flux:modal>
                    @endforeach
                @endif
            </section>
        @endif
    </div>

    <flux:modal name="change-access-mode" class="w-full max-w-lg">
        @if ($this->chosenMode !== null)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Switch to :mode?', ['mode' => $this->chosenMode->label()]) }}</flux:heading>
                    <flux:text class="mt-2">
                        @if ($star->access_mode === App\Enums\StarAccessMode::SignedUrl)
                            {{ __('Its signed URL stops working at once, and switching back later gives it a new one.') }}
                        @elseif ($star->access_mode === App\Enums\StarAccessMode::OAuth)
                            {{ __('Its connected apps are revoked, so they stop reaching the Star at once, and switching back means approving each of them again.') }}
                        @else
                            {{ __('Its tokens are revoked, so clients using them stop reaching the Star at once.') }}
                        @endif
                        {{ __('This can\'t be undone.') }}
                    </flux:text>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="danger" wire:click="changeAccessMode">{{ __('Switch to :mode', ['mode' => $this->chosenMode->label()]) }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal name="rotate-signed-url" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Rotate the signed URL?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Clients using the current URL stop reaching the Star at once. Give them the new URL from this page. This can\'t be undone.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="rotateSignedUrl">{{ __('Rotate URL') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    @if ($star->access_mode === App\Enums\StarAccessMode::Token)
        <flux:modal name="new-token" class="w-full max-w-2xl" :dismissible="false" @close="forgetNewToken">
            @if ($newToken === null)
                <form wire:submit="create" class="space-y-6" data-new-token-form>
                    <div>
                        <flux:heading size="lg">{{ __('Create a token') }}</flux:heading>
                        <flux:text class="mt-2">{{ __('Name it after the client that uses it, so you know which one to revoke later.') }}</flux:text>
                    </div>

                    <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. Claude Code on my laptop')" maxlength="100" autofocus />

                    <flux:error name="limit" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary">{{ __('Create token') }}</flux:button>
                    </div>
                </form>
            @else
                <div class="space-y-6" x-init="$nextTick(() => $el.querySelector('[data-code-panel-copy]')?.focus())" data-new-token>
                    <div>
                        <flux:heading size="lg">{{ __('Put the token in your shell') }}</flux:heading>
                        <flux:text class="mt-2 wrap-anywhere">{{ __('Add this line to your shell profile, so your clients read the token from :variable.', ['variable' => $this->tokenVariable]) }}</flux:text>
                    </div>

                    <x-code-panel :file="App\Stars\ClientSetup::SHELL_PROFILE" :code="App\Stars\ClientSetup::tokenExport($star, $newToken)" />

                    <p class="flex items-start gap-2.5 rounded-lg border border-warning-rule bg-warning-wash px-3.5 py-3 text-sm text-warning" data-new-token-once>
                        <flux:icon.eye-slash variant="micro" class="mt-0.5 size-4 shrink-0" />
                        <span>{{ __('This is the only time Nexus shows this token. If you lose it, create another and revoke this one.') }}</span>
                    </p>

                    <div class="flex flex-wrap justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Done') }}</flux:button>
                        </flux:modal.close>

                        {{-- Close the modal and forget the token before leaving, so going back doesn't show it again. --}}
                        <flux:button
                            variant="primary"
                            icon:trailing="arrow-right"
                            :href="route('stars.show', $star).'#setup'"
                            x-on:click.prevent="$flux.modal('new-token').close(); $wire.forgetNewToken().then(() => Livewire.navigate($el.href))"
                            data-new-token-next
                        >{{ __('Next: set up a client') }}</flux:button>
                    </div>
                </div>
            @endif
        </flux:modal>
    @endif
</div>
