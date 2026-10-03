<div class="mx-auto w-full max-w-3xl">
    <flux:heading size="xl" level="1">{{ __('Settings') }}</flux:heading>
    <flux:text class="mt-2">{{ __('Your GitHub profile, how Nexus looks, and your account.') }}</flux:text>

    <div class="mt-8 space-y-6">
        <x-section-card :heading="__('Profile')" :description="__('Nexus takes your profile from GitHub and refreshes it every time you sign in.')">
            <div class="flex items-center gap-4">
                <flux:avatar size="lg" :src="$this->user->avatar_url" :name="$this->user->name" :initials="$this->user->initials()" />

                <div class="min-w-0">
                    <flux:heading class="truncate">{{ $this->user->name }}</flux:heading>
                    <flux:text class="truncate">{{ '@'.$this->user->github_login }}</flux:text>
                </div>
            </div>

            <dl class="mt-6 divide-y divide-zinc-200 border-t border-zinc-200 dark:divide-white/10 dark:border-white/10">
                <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                    <dt><flux:text>{{ __('Name') }}</flux:text></dt>
                    <dd class="min-w-0 sm:col-span-2"><flux:text variant="strong" class="break-words">{{ $this->user->name }}</flux:text></dd>
                </div>

                <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                    <dt><flux:text>{{ __('GitHub login') }}</flux:text></dt>
                    <dd class="min-w-0 sm:col-span-2"><flux:text variant="strong" class="break-words font-mono">{{ $this->user->github_login }}</flux:text></dd>
                </div>

                <div class="grid gap-1 pt-3 sm:grid-cols-3 sm:gap-4">
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

            <x-slot:hint>{{ __('To change your profile, change it on GitHub and sign in again.') }}</x-slot:hint>
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
                :label="__('Type your GitHub login, :login, to confirm', ['login' => $this->user->github_login])"
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
