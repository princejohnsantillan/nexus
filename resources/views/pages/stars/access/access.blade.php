<div class="mx-auto w-full max-w-5xl">
    <x-star-header :star="$star" current="access" />

    <div class="mt-8 space-y-10">
        <section aria-labelledby="mode-heading">
            <flux:heading size="lg" level="2" id="mode-heading">{{ __('Access mode') }}</flux:heading>
            <flux:text class="mt-1">{{ __('How clients authenticate to this Star. It accepts only its current mode, so switching stops clients using the old one.') }}</flux:text>

            <flux:radio.group wire:model.live="accessMode" variant="cards" class="mt-4 max-w-3xl max-sm:flex-col" :aria-label="__('Access mode')">
                @foreach (App\Enums\StarAccessMode::cases() as $option)
                    <flux:radio :value="$option->value" :label="$option->label()" :description="$option->description()" wire:key="access-mode-{{ $option->value }}" />
                @endforeach
            </flux:radio.group>

            <flux:error name="accessMode" class="mt-2" />

            @if ($this->chosenMode !== null && $this->chosenMode !== $star->access_mode)
                <flux:modal.trigger name="change-access-mode">
                    <flux:button variant="primary" class="mt-4">{{ __('Switch to :mode', ['mode' => $this->chosenMode->label()]) }}</flux:button>
                </flux:modal.trigger>
            @endif
        </section>

        @if ($star->access_mode === App\Enums\StarAccessMode::SignedUrl)
            <section aria-labelledby="signed-url-heading">
                <flux:heading size="lg" level="2" id="signed-url-heading">{{ __('Signed URL') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Anyone who has this URL can use the Star, so treat it like a password. Rotating it gives the Star a new URL and stops the old one working at once.') }}</flux:text>

                <div class="mt-4 max-w-xl">
                    <flux:input :value="$star->signedUrl()" readonly copyable class:input="font-mono" :aria-label="__('Signed URL')" />
                </div>

                <flux:modal.trigger name="rotate-signed-url">
                    <flux:button icon="arrow-path" class="mt-4">{{ __('Rotate URL') }}</flux:button>
                </flux:modal.trigger>
            </section>
        @else
            <section aria-labelledby="tokens-heading">
                <flux:heading size="lg" level="2" id="tokens-heading">{{ __('Tokens') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Clients reach this Star with one of its tokens, sent as "Authorization: Bearer nxs_…". Give each client its own token, so you can revoke one without the others. The setup on the overview reads it from :variable.', ['variable' => $this->tokenVariable]) }}
                </flux:text>

                @if ($this->limitMessage !== null)
                    <flux:callout icon="exclamation-triangle" color="amber" class="mt-4 max-w-xl" :heading="__('Token limit reached')">
                        <flux:callout.text>{{ $this->limitMessage }}</flux:callout.text>
                    </flux:callout>
                @else
                    <form wire:submit="create" class="mt-4 flex max-w-xl flex-wrap items-end gap-3">
                        <div class="min-w-0 flex-1">
                            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. Claude Code on my laptop')" maxlength="100" />
                        </div>

                        <flux:button type="submit" variant="primary" icon="plus">{{ __('Create token') }}</flux:button>
                    </form>

                    <flux:error name="limit" class="mt-2" />
                @endif

                @if ($this->tokens->isEmpty())
                    <x-empty-state icon="key" :heading="__('No tokens yet')" class="mt-6">
                        {{ __('Create a token for each client you add this Star to. Nexus shows it once, so copy it then.') }}
                    </x-empty-state>
                @else
                    <flux:table class="mt-6">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Token') }}</flux:table.column>
                            <flux:table.column>{{ __('Created') }}</flux:table.column>
                            <flux:table.column>{{ __('Last used') }}</flux:table.column>
                            <flux:table.column class="w-0"><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->tokens as $token)
                                <flux:table.row :key="$token->id">
                                    <flux:table.cell class="max-w-xs truncate font-medium">{{ $token->name }}</flux:table.cell>
                                    <flux:table.cell class="font-mono">{{ $token->prefix }}…</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($token->created_at !== null)
                                            <time datetime="{{ $token->created_at->toIso8601String() }}" title="{{ $token->created_at->toDayDateTimeString() }}">{{ $token->created_at->diffForHumans() }}</time>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($token->last_used_at !== null)
                                            <time datetime="{{ $token->last_used_at->toIso8601String() }}" title="{{ $token->last_used_at->toDayDateTimeString() }}">{{ $token->last_used_at->diffForHumans() }}</time>
                                        @else
                                            <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500">{{ __('Never') }}</flux:text>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end">
                                        <flux:modal.trigger :name="'revoke-token-'.$token->id">
                                            <flux:button size="sm" variant="ghost">{{ __('Revoke') }}</flux:button>
                                        </flux:modal.trigger>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>

                    @foreach ($this->tokens as $token)
                        <flux:modal :name="'revoke-token-'.$token->id" class="w-full max-w-lg" wire:key="revoke-token-{{ $token->id }}">
                            <div class="space-y-6">
                                <div>
                                    <flux:heading size="lg">{{ __('Revoke :name?', ['name' => $token->name]) }}</flux:heading>
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

    <flux:modal name="new-token" class="w-full max-w-xl" @close="forgetNewToken">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Copy your new token') }}</flux:heading>
                <flux:text class="mt-2">{{ __('This is the only time Nexus shows it. Store it where your client reads it, in :variable.', ['variable' => $this->tokenVariable]) }}</flux:text>
            </div>

            @if ($newToken !== null)
                <flux:input :value="$newToken" readonly copyable class:input="font-mono" :aria-label="__('New token')" />
            @endif

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="primary">{{ __('Done') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
