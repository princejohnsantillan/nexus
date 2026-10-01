<div class="mx-auto w-full max-w-5xl">
    <x-star-header :star="$star" current="access" />

    <div class="mt-8 space-y-10">
        <section aria-labelledby="mode-heading">
            <flux:heading size="lg" level="2" id="mode-heading">{{ $star->access_mode->label() }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Clients reach this Star with one of its tokens, sent as "Authorization: Bearer nxs_…". Give each client its own token, so you can revoke one without the others. The setup on the overview reads it from :variable.', ['variable' => $this->tokenVariable]) }}
            </flux:text>
        </section>

        <section aria-labelledby="tokens-heading">
            <flux:heading size="lg" level="2" id="tokens-heading">{{ __('Tokens') }}</flux:heading>

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
    </div>

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
