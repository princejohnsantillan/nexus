<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="w-62 gap-6 px-3.5 py-5 border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo sidebar :href="route('stars.index')" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="star" :href="route('stars.index')" :current="request()->routeIs('stars.*')" :accent="false" wire:navigate>
                    {{ __('Stars') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="link" :href="route('connections.index')" :current="request()->routeIs('connections.*')" :accent="false" wire:navigate>
                    {{ __('Connections') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="pulse" :href="route('activity.index')" :current="request()->routeIs('activity.*')" :accent="false" wire:navigate>
                    {{ __('Activity') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <flux:sidebar.spacer />

            <flux:dropdown position="top" align="start" class="max-lg:hidden">
                <button type="button" class="group flex w-full items-center gap-2.5 rounded-lg p-1.5 text-start hover:bg-zinc-800/5 dark:hover:bg-white/10" data-flux-sidebar-profile>
                    <flux:avatar size="xs" circle :src="auth()->user()->avatar_url" :name="auth()->user()->name" :initials="auth()->user()->initials()" class="size-7 bg-accent-wash text-accent-content" />

                    <span class="grid min-w-0 flex-1 leading-tight">
                        <span class="truncate text-[13px] font-medium text-zinc-950 dark:text-white">{{ auth()->user()->name }}</span>
                        <span class="truncate text-xs text-zinc-600 dark:text-zinc-400">{{ '@'.auth()->user()->github_login }}</span>
                    </span>

                    <flux:icon.chevron-up-down variant="micro" class="text-zinc-400 group-hover:text-zinc-800 dark:text-white/60 dark:group-hover:text-white" />
                </button>

                <x-profile-menu />
            </flux:dropdown>
        </flux:sidebar>

        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" :aria-label="__('Open menu')" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile :avatar="auth()->user()->avatar_url" :initials="auth()->user()->initials()" :aria-label="__('Profile menu')" />
                <x-profile-menu />
            </flux:dropdown>
        </flux:header>

        <flux:main>
            {{ $slot }}
        </flux:main>

        <x-flash-toast />

        @fluxScripts
    </body>
</html>
