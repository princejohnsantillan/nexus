<div class="mx-auto w-full max-w-3xl">
    <flux:heading size="xl" level="1">{{ __('Settings') }}</flux:heading>
    <flux:text class="mt-2">{{ __('Your GitHub profile, how Nexus looks, and your account.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    <section aria-labelledby="profile-heading">
        <flux:heading size="lg" level="2" id="profile-heading">{{ __('Profile') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Nexus takes your profile from GitHub and refreshes it every time you sign in.') }}</flux:text>

        <div class="mt-6 flex items-center gap-4">
            <flux:avatar size="lg" :src="$this->user->avatar_url" :name="$this->user->name" :initials="$this->user->initials()" />

            <div class="min-w-0">
                <flux:heading class="truncate">{{ $this->user->name }}</flux:heading>
                <flux:text class="truncate">{{ '@'.$this->user->github_login }}</flux:text>
            </div>
        </div>

        <dl class="mt-6 divide-y divide-zinc-200 border-t border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                <dt><flux:text>{{ __('Name') }}</flux:text></dt>
                <dd class="min-w-0 sm:col-span-2"><flux:text variant="strong" class="break-words">{{ $this->user->name }}</flux:text></dd>
            </div>

            <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                <dt><flux:text>{{ __('GitHub login') }}</flux:text></dt>
                <dd class="min-w-0 sm:col-span-2"><flux:text variant="strong" class="break-words">{{ $this->user->github_login }}</flux:text></dd>
            </div>

            <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                <dt><flux:text>{{ __('Email') }}</flux:text></dt>
                <dd class="min-w-0 sm:col-span-2">
                    @if (filled($this->user->email))
                        <flux:text variant="strong" class="break-words">{{ $this->user->email }}</flux:text>
                    @else
                        <flux:text>{{ __('Not shared by GitHub') }}</flux:text>
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    <flux:separator variant="subtle" class="my-8" />

    <section aria-labelledby="appearance-heading">
        <flux:heading size="lg" level="2" id="appearance-heading">{{ __('Appearance') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Light, dark, or follow your system. Nexus remembers your choice in this browser.') }}</flux:text>

        <x-appearance-switch class="mt-4 max-w-sm" />
    </section>

    <flux:separator variant="subtle" class="my-8" />

    <section aria-labelledby="delete-account-heading">
        <flux:heading size="lg" level="2" id="delete-account-heading">{{ __('Delete account') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Deletes your account with all of its Stars, Connections, tokens, connected apps and activity, and destroys your encryption key so your stored credentials can never be read again. This can\'t be undone.') }}</flux:text>

        <flux:modal.trigger name="delete-account">
            <flux:button variant="danger" class="mt-4">{{ __('Delete account') }}</flux:button>
        </flux:modal.trigger>
    </section>

    <flux:modal name="delete-account" class="w-full max-w-lg">
        <form wire:submit="deleteAccount" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete your account?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Your Stars stop working in every client, and your Connections, tokens, connected apps and activity are deleted for good.') }}</flux:text>
            </div>

            <flux:input
                wire:model="confirmation"
                :label="__('Type your GitHub login, :login, to confirm', ['login' => $this->user->github_login])"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
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
