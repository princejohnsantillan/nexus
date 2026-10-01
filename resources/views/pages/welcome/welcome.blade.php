<div class="flex min-h-screen flex-col">
    <header class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-6 py-4">
        <x-app-logo :href="route('home')" />

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
    </header>

    <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col px-6 py-12 sm:py-20">
        <div class="max-w-2xl">
            <flux:badge icon="star">{{ __('Remote MCP gateway') }}</flux:badge>

            <flux:heading size="xl" level="1" class="mt-6 text-4xl! leading-tight! sm:text-5xl!">
                {{ __('Connect your MCP servers once. Use them in every AI client.') }}
            </flux:heading>

            <flux:text size="lg" class="mt-6">
                {{ __('Nexus signs in to GitHub, Notion, Linear and any other remote MCP server for you, then serves them through Stars: MCP endpoints you add to Claude Code, claude.ai, Codex, Cursor and Grok. Decide what each client can do here, not in a pile of config files.') }}
            </flux:text>

            <div class="mt-8 flex flex-wrap items-center gap-3">
                <flux:button variant="primary" :href="route('auth.github')">
                    <x-icons.github class="size-4" />
                    {{ __('Sign in with GitHub') }}
                </flux:button>
            </div>

            @if ($this->devSignInIsEnabled)
                <flux:callout icon="wrench-screwdriver" class="mt-8" :heading="__('Dev sign-in')">
                    <flux:callout.text>{{ __('Local only. Sign in as a seeded user without a GitHub OAuth app.') }}</flux:callout.text>

                    <x-slot name="actions">
                        <flux:button size="sm" :href="route('dev.sign-in', 'dev')">{{ __('Sign in as Dev User') }}</flux:button>
                        <flux:button size="sm" :href="route('dev.sign-in', 'second')">{{ __('Sign in as Second User') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @endif
        </div>

        <div class="mt-16 grid gap-4 sm:grid-cols-2">
            <flux:card class="space-y-2">
                <flux:icon.link class="text-zinc-500 dark:text-zinc-400" />
                <flux:heading level="2">{{ __('Connect each server once') }}</flux:heading>
                <flux:text>{{ __('Pick a service from the gallery or enter any remote MCP server. Your credentials are encrypted with a key of your own.') }}</flux:text>
            </flux:card>

            <flux:card class="space-y-2">
                <flux:icon.star class="text-zinc-500 dark:text-zinc-400" />
                <flux:heading level="2">{{ __('Bundle them into Stars') }}</flux:heading>
                <flux:text>{{ __('A Star is one MCP endpoint with the Connections you choose. Clients reach it with a token, a signed URL or by signing in to Nexus.') }}</flux:text>
            </flux:card>

            <flux:card class="space-y-2">
                <flux:icon.adjustments-horizontal class="text-zinc-500 dark:text-zinc-400" />
                <flux:heading level="2">{{ __('Switch every tool on or off') }}</flux:heading>
                <flux:text>{{ __('Read-only tools start on and everything else starts off, so a new Star is safe. Change it per Star at any time.') }}</flux:text>
            </flux:card>

            <flux:card class="space-y-2">
                <flux:icon.queue-list class="text-zinc-500 dark:text-zinc-400" />
                <flux:heading level="2">{{ __('See what your agents did') }}</flux:heading>
                <flux:text>{{ __('Every tool call is recorded with its time, status and duration. Arguments and results are never stored.') }}</flux:text>
            </flux:card>
        </div>
    </main>

    <footer class="mx-auto w-full max-w-5xl px-6 py-8">
        <flux:text size="sm">
            {{ __('Nexus is open source.') }}
            <flux:link href="https://github.com/princejohnsantillan/nexus" external>{{ __('View it on GitHub') }}</flux:link>
        </flux:text>
    </footer>
</div>
