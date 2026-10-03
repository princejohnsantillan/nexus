<div class="flex min-h-screen flex-col">
    <x-public-header />

    <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col px-6 py-12 sm:py-20">
        <div class="max-w-2xl">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-accent-wash px-2.5 py-1 text-xs font-medium text-accent-content">
                <flux:icon.star variant="micro" class="size-3.5" />
                {{ __('Remote MCP gateway') }}
            </span>

            <flux:heading size="xl" level="1" class="mt-6 text-4xl! leading-tight! font-bold! tracking-tight text-zinc-950 sm:text-5xl! dark:text-white">
                {{ __('Connect your MCP servers once.') }}
                <span class="text-accent-content">{{ __('Use them in every AI client.') }}</span>
            </flux:heading>

            <flux:text size="lg" class="mt-6 text-zinc-600 dark:text-zinc-400">
                {{ __('Nexus signs in to GitHub, Notion, Linear and any other remote MCP server for you, then serves them through Stars: MCP endpoints you add to Claude Code, claude.ai, Codex, Cursor and Grok. Decide what each client can do here, not in a pile of config files.') }}
            </flux:text>

            <div class="mt-8 flex flex-wrap items-center gap-x-4 gap-y-3">
                <flux:button variant="primary" :href="route('auth.sign-in')" icon:trailing="arrow-right">{{ __('Get started') }}</flux:button>
                <flux:text>{{ __('New here? Signing in creates your account.') }}</flux:text>
            </div>
        </div>

        <x-star-chart class="mt-14 hidden rounded-xl ring-1 ring-zinc-950 sm:flex dark:ring-white/10" />

        <div class="mt-16 grid gap-4 sm:grid-cols-2">
            <flux:card class="space-y-2">
                <flux:icon.link class="text-accent-content" />
                <flux:heading level="2">{{ __('Connect each server once') }}</flux:heading>
                <flux:text>{{ __('Pick a service from the gallery or enter any remote MCP server. Your credentials are encrypted with a key of your own.') }}</flux:text>
            </flux:card>

            <flux:card class="space-y-2">
                <flux:icon.star class="text-accent-content" />
                <flux:heading level="2">{{ __('Bundle them into Stars') }}</flux:heading>
                <flux:text>{{ __('A Star is one MCP endpoint with the Connections you choose. Clients reach it with a token, a signed URL or by signing in to Nexus.') }}</flux:text>
            </flux:card>

            <flux:card class="space-y-2">
                <flux:icon.adjustments-horizontal class="text-accent-content" />
                <flux:heading level="2">{{ __('Switch every tool on or off') }}</flux:heading>
                <flux:text>{{ __('Read-only tools start on and everything else starts off, so a new Star is safe. Change it per Star at any time.') }}</flux:text>
            </flux:card>

            <flux:card class="space-y-2">
                <flux:icon.pulse class="text-accent-content" />
                <flux:heading level="2">{{ __('See what your agents did') }}</flux:heading>
                <flux:text>{{ __('Every tool call is recorded with its time, status and duration. Arguments and results are never stored.') }}</flux:text>
            </flux:card>
        </div>
    </main>

    <x-public-footer />
</div>
