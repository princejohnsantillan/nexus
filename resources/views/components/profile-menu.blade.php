<flux:menu class="min-w-64">
    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
        <flux:avatar size="sm" :src="auth()->user()->avatar_url" :name="auth()->user()->name" :initials="auth()->user()->initials()" />

        <div class="grid min-w-0 flex-1 leading-tight">
            <span class="truncate font-medium text-zinc-800 dark:text-white">{{ auth()->user()->name }}</span>
            <span class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ auth()->user()->signInName() }}</span>
        </div>
    </div>

    <flux:menu.separator />

    <flux:menu.item icon="credit-card" :href="route('billing.index')" wire:navigate>{{ __('Billing') }}</flux:menu.item>
    <flux:menu.item icon="cog-6-tooth" :href="route('settings.index')" wire:navigate>{{ __('Settings') }}</flux:menu.item>

    <flux:menu.separator />

    <flux:menu.heading>{{ __('Appearance') }}</flux:menu.heading>

    <div class="px-1 pb-1">
        <x-appearance-switch size="sm" />
    </div>

    <flux:menu.separator />

    <form method="POST" action="{{ route('logout') }}" class="w-full">
        @csrf
        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">{{ __('Sign out') }}</flux:menu.item>
    </form>
</flux:menu>
