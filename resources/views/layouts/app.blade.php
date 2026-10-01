<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo sidebar :href="route('stars.index')" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="star" :href="route('stars.index')" :current="request()->routeIs('stars.*')" wire:navigate>
                    {{ __('Stars') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="link" :href="route('connections.index')" :current="request()->routeIs('connections.*')" wire:navigate>
                    {{ __('Connections') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="queue-list" :href="route('activity.index')" :current="request()->routeIs('activity.*')" wire:navigate>
                    {{ __('Activity') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <flux:sidebar.spacer />

            <flux:dropdown position="top" align="start" class="max-lg:hidden">
                <flux:sidebar.profile :name="auth()->user()->name ?? __('Guest')" :initials="auth()->user()?->initials() ?? 'G'" icon:trailing="chevron-up-down" />
                <x-profile-menu />
            </flux:dropdown>
        </flux:sidebar>

        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" :aria-label="__('Open menu')" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile :initials="auth()->user()?->initials() ?? 'G'" :aria-label="__('Profile menu')" />
                <x-profile-menu />
            </flux:dropdown>
        </flux:header>

        <flux:main>
            {{ $slot }}
        </flux:main>

        @fluxScripts
    </body>
</html>
