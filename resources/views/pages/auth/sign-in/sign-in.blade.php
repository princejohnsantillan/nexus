<x-sign-in-frame>
    <flux:heading level="1" class="text-[2rem]! leading-[2.375rem]! font-bold! tracking-tight text-zinc-950 dark:text-white">{{ __('Sign in to Nexus') }}</flux:heading>

    <flux:text class="mt-2.5 leading-[1.375rem] text-zinc-600 dark:text-zinc-400">
        {{ __('Connect your MCP servers once, then use them in every AI client. New here? Signing in creates your account.') }}
    </flux:text>

    <flux:button
        variant="primary"
        :href="route('auth.github')"
        class="mt-7 h-10.5! w-full gap-2.5! [--color-accent-foreground:var(--color-white)] [--color-accent:var(--color-zinc-950)] dark:[--color-accent-foreground:var(--color-zinc-950)] dark:[--color-accent:var(--color-white)]"
    >
        <x-icons.github class="size-4.25" />
        {{ __('Continue with GitHub') }}
    </flux:button>

    @if ($this->emailSignInIsEnabled)
        <flux:separator
            :text="__('or use your email')"
            class="mt-7 [&>div]:bg-zinc-200 dark:[&>div]:bg-white/10 [&>span]:mx-3 [&>span]:text-[0.8125rem] [&>span]:leading-4 [&>span]:font-normal dark:[&>span]:text-zinc-400"
        />

        <form wire:submit="sendCode" class="mt-7">
            {{-- Board S1's field: a 13px label 6px above a 42px input with a strong rule. --}}
            <flux:field class="*:data-flux-label:mb-1.5! [&>[data-flux-label]]:flex [&_input]:h-10.5 [&_input]:border-zinc-300 [&_input]:px-3.5 [&_input]:text-zinc-950 dark:[&_input]:border-white/10 dark:[&_input]:text-white">
                <flux:label class="text-[0.8125rem]! leading-4 text-zinc-950 dark:text-white">{{ __('Email') }}</flux:label>

                <flux:input
                    wire:model="email"
                    type="email"
                    autocomplete="email"
                    autocapitalize="off"
                    spellcheck="false"
                    required
                />

                <flux:error name="email" />
            </flux:field>

            <flux:button type="submit" icon="envelope" icon:variant="outline" class="mt-3 h-10.5! w-full border-zinc-300! text-zinc-950! dark:border-zinc-600! dark:text-white!">{{ __('Email me a sign-in code') }}</flux:button>

            <flux:text class="mt-3 text-[0.8125rem] leading-5 text-zinc-600 dark:text-zinc-400">{{ __('We\'ll send a 6-digit code. No password to remember.') }}</flux:text>
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
