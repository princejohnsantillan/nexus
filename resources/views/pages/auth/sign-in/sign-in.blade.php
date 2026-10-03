<x-sign-in-frame>
    <flux:heading level="1" class="text-[2rem]! leading-[2.375rem]! font-bold! tracking-tight text-zinc-950 dark:text-white">{{ __('Sign in to Nexus') }}</flux:heading>

    <flux:text class="mt-2.5 leading-[1.375rem] text-zinc-600 dark:text-zinc-400">
        {{ __('Connect your MCP servers once, then use them in every AI client. New here? Signing in creates your account.') }}
    </flux:text>

    {{-- Each way to sign in stacks here, GitHub first. --}}
    <div class="mt-7 flex flex-col gap-2.5">
        <flux:button
            variant="primary"
            :href="route('auth.github')"
            class="w-full [--color-accent-foreground:var(--color-white)] [--color-accent:var(--color-zinc-950)] dark:[--color-accent-foreground:var(--color-zinc-950)] dark:[--color-accent:var(--color-white)]"
        >
            <x-icons.github class="size-4" />
            {{ __('Continue with GitHub') }}
        </flux:button>

        @if ($this->googleSignInIsEnabled)
            <flux:button :href="route('auth.google')" class="w-full">
                <x-icons.google class="size-4" />
                {{ __('Continue with Google') }}
            </flux:button>
        @endif
    </div>

    @if ($this->emailSignInIsEnabled)
        <flux:separator variant="subtle" :text="__('or use your email')" class="mt-7 [&>span]:mx-3 [&>span]:font-normal dark:[&>span]:text-zinc-400" />

        <form wire:submit="sendCode" class="mt-6">
            <flux:input
                wire:model="email"
                type="email"
                :label="__('Email')"
                autocomplete="email"
                autocapitalize="off"
                spellcheck="false"
                required
            />

            <flux:button type="submit" icon="envelope" icon:variant="outline" class="mt-3 w-full">{{ __('Email me a sign-in code') }}</flux:button>

            <flux:text size="sm" class="mt-3 text-zinc-500 dark:text-zinc-400">{{ __('We\'ll send a 6-digit code. No password to remember.') }}</flux:text>
        </form>
    @endif

    @if ($this->devSignInIsEnabled)
        <div class="mt-7 rounded-xl border border-dashed border-zinc-300 p-4 dark:border-zinc-600">
            <div class="flex items-center gap-2">
                <flux:icon.wrench-screwdriver variant="micro" class="text-zinc-500 dark:text-zinc-400" />
                <flux:heading level="2">{{ __('Dev sign-in') }}</flux:heading>
            </div>

            <flux:text class="mt-1">{{ __('Local only. Sign in as a seeded user without a GitHub OAuth app.') }}</flux:text>

            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                <flux:button size="sm" :href="route('dev.sign-in', 'dev')">{{ __('Sign in as Dev User') }}</flux:button>
                <flux:button size="sm" :href="route('dev.sign-in', 'second')">{{ __('Sign in as Second User') }}</flux:button>
            </div>
        </div>
    @endif
</x-sign-in-frame>
