<div class="mx-auto w-full max-w-3xl">
    <flux:heading size="xl" level="1">{{ __('Settings') }}</flux:heading>
    <flux:text class="mt-2">{{ __('Your profile, how you sign in, how Nexus looks, and your account.') }}</flux:text>

    <div class="mt-8 space-y-6">
        <x-section-card :heading="__('Profile')" :description="$this->user->gitHubLogin() !== null ? __('Nexus takes your profile from GitHub and refreshes it every time you sign in.') : __('Your name and email address in Nexus.')">
            <div class="flex items-center gap-4">
                <flux:avatar size="lg" :src="$this->user->avatar_url" :name="$this->user->name" :initials="$this->user->initials()" />

                <div class="min-w-0">
                    <flux:heading class="truncate">{{ $this->user->name }}</flux:heading>
                    @if (filled($this->user->signInName()))
                        <flux:text class="truncate">{{ $this->user->signInName() }}</flux:text>
                    @endif
                </div>
            </div>

            <dl class="mt-6 divide-y divide-zinc-200 border-t border-zinc-200 dark:divide-white/10 dark:border-white/10">
                <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                    <dt><flux:text>{{ __('Name') }}</flux:text></dt>
                    <dd class="min-w-0 sm:col-span-2"><flux:text variant="strong" class="break-words">{{ $this->user->name }}</flux:text></dd>
                </div>

                <div class="grid gap-1 pt-3 sm:grid-cols-3 sm:gap-4">
                    <dt><flux:text>{{ __('Email') }}</flux:text></dt>
                    <dd class="min-w-0 sm:col-span-2">
                        @if (filled($this->user->email))
                            <flux:text variant="strong" class="break-words">{{ $this->user->email }}</flux:text>
                        @elseif ($this->user->gitHubLogin() !== null)
                            <flux:text>{{ __('Not shared by GitHub') }}</flux:text>
                        @else
                            <flux:text>{{ __('None') }}</flux:text>
                        @endif
                    </dd>
                </div>
            </dl>

            @if ($this->user->gitHubLogin() !== null)
                <x-slot:hint>{{ __('To change your profile, change it on GitHub and sign in again.') }}</x-slot:hint>
            @endif
        </x-section-card>

        <x-section-card :heading="__('Sign-in methods')" :description="__('You can sign in to this account with any of these.')">
            @if ($this->identities->isEmpty())
                <x-empty-state icon="key" :heading="__('No sign-in methods')">
                    {{ __('This account has no way to sign in yet.') }}
                </x-empty-state>
            @else
                <ul role="list" class="-my-3 divide-y divide-zinc-200 dark:divide-white/10">
                    @foreach ($this->identities as $identity)
                        <li class="flex items-center gap-3 py-3" wire:key="sign-in-identity-{{ $identity->id }}">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-white" aria-hidden="true">
                                @switch($identity->provider)
                                    @case(App\Enums\IdentityProvider::GitHub)
                                        <x-icons.github class="size-4" />
                                        @break
                                    @case(App\Enums\IdentityProvider::Google)
                                        <x-icons.google class="size-4" />
                                        @break
                                    @default
                                        <flux:icon.envelope variant="micro" class="size-4 text-zinc-500 dark:text-zinc-300" />
                                @endswitch
                            </div>

                            <div class="min-w-0 flex-1">
                                <flux:heading>{{ $identity->provider->label() }}</flux:heading>
                                <flux:text @class(['truncate', 'font-mono' => $identity->provider === App\Enums\IdentityProvider::GitHub])>{{ $identity->displayName() }}</flux:text>
                            </div>

                            @if ($identity->created_at !== null)
                                <flux:text size="sm" class="shrink-0 max-sm:hidden">
                                    {{ __('Added') }} <time datetime="{{ $identity->created_at->toIso8601String() }}" title="{{ $identity->created_at->toDayDateTimeString() }}">{{ $identity->created_at->diffForHumans() }}</time>
                                </flux:text>
                            @endif

                            @if ($this->identities->count() > 1)
                                <flux:modal.trigger :name="'remove-sign-in-identity-'.$identity->id">
                                    <flux:button size="sm" variant="ghost" class="shrink-0" :aria-label="__('Remove :provider, :name', ['provider' => $identity->provider->label(), 'name' => $identity->displayName()])">{{ __('Remove') }}</flux:button>
                                </flux:modal.trigger>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($this->identities->count() === 1)
                    <div class="mt-6 flex items-start gap-2">
                        <flux:icon.information-circle variant="micro" class="mt-0.5 shrink-0 text-zinc-500 dark:text-zinc-400" />
                        <flux:text>{{ __("This is your only way to sign in, so it can't be removed. To remove it, add another sign-in method first.") }}</flux:text>
                    </div>
                @endif

                {{-- Every row's confirmation stays on the page, so one refused because another method went first can still say why. --}}
                @foreach ($this->identities as $identity)
                    <flux:modal :name="'remove-sign-in-identity-'.$identity->id" class="w-full max-w-lg" wire:key="remove-sign-in-identity-{{ $identity->id }}">
                        <div class="space-y-6">
                            <div>
                                <flux:heading size="lg">{{ __('Remove :provider?', ['provider' => $identity->provider->label()]) }}</flux:heading>
                                <flux:text class="mt-2">{{ __('You will no longer sign in to this account with :name. Signing in with it later creates a new, empty Nexus account.', ['name' => $identity->displayName()]) }}</flux:text>
                            </div>

                            <flux:error name="identity" />

                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>

                                <flux:button variant="danger" wire:click="removeIdentity({{ $identity->id }})">{{ __('Remove :provider', ['provider' => $identity->provider->label()]) }}</flux:button>
                            </div>
                        </div>
                    </flux:modal>
                @endforeach
            @endif

            <x-slot:hint>{{ __('Each one signs in to this account. Nexus never joins accounts by matching email addresses.') }}</x-slot:hint>
            <x-slot:actions>
                @if ($this->googleSignInIsEnabled)
                    <flux:button size="sm" :href="route('settings.add-google')">
                        <x-icons.google class="size-4" />
                        {{ __('Add Google') }}
                    </flux:button>
                @endif
            </x-slot:actions>
        </x-section-card>

        <x-section-card :heading="__('Appearance')" :description="__('Light, dark, or follow your system.')">
            <x-appearance-switch class="max-w-sm" />

            <x-slot:hint>{{ __('Nexus remembers your choice in this browser.') }}</x-slot:hint>
        </x-section-card>

        <x-danger-card :heading="__('Delete account')" :description="__('Deleting your account permanently removes your Stars, Connections, tokens, connected apps, activity and encryption key. Backups are kept for a limited time and then removed.')">
            <x-slot:actions>
                <flux:modal.trigger name="delete-account">
                    <flux:button variant="danger" size="sm">{{ __('Delete account') }}</flux:button>
                </flux:modal.trigger>
            </x-slot:actions>
        </x-danger-card>
    </div>

    <flux:modal name="delete-account" class="w-full max-w-lg">
        <form wire:submit="deleteAccount" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete your account?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Your Stars stop working in every client right away, and your Connections, tokens, connected apps, activity and encryption key are removed. Backups are kept for a limited time and then removed.') }}</flux:text>
            </div>

            <flux:input
                wire:model="confirmation"
                :label="$this->deletionConfirmation['label']"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                class:input="font-mono"
            />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="danger">{{ __('Delete account') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
