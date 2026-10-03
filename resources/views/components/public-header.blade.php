<header class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-6 py-4">
    <x-app-logo :href="route('home')" />

    <div class="flex items-center gap-2">
        <flux:dropdown x-data align="end">
            <flux:button variant="subtle" square :aria-label="__('Appearance')">
                <flux:icon.sun x-show="$flux.appearance === 'light'" variant="mini" />
                <flux:icon.moon x-show="$flux.appearance === 'dark'" variant="mini" />
                <flux:icon.moon x-show="$flux.appearance === 'system' && $flux.dark" variant="mini" />
                <flux:icon.sun x-show="$flux.appearance === 'system' && ! $flux.dark" variant="mini" />
            </flux:button>

            <flux:menu>
                <flux:menu.item icon="sun" x-on:click="$flux.appearance = 'light'">{{ __('Light') }}</flux:menu.item>
                <flux:menu.item icon="moon" x-on:click="$flux.appearance = 'dark'">{{ __('Dark') }}</flux:menu.item>
                <flux:menu.item icon="computer-desktop" x-on:click="$flux.appearance = 'system'">{{ __('System') }}</flux:menu.item>
            </flux:menu>
        </flux:dropdown>

        @auth
            <flux:button size="sm" :href="route('stars.index')">{{ __('Go to Stars') }}</flux:button>
        @else
            <flux:button size="sm" :href="route('auth.sign-in')">{{ __('Sign in') }}</flux:button>
        @endauth
    </div>
</header>
