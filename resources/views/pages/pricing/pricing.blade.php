<div class="flex min-h-screen flex-col">
    <x-public-header current="pricing" />

    <main class="flex w-full flex-1 flex-col items-center px-6">
        <div class="flex max-w-5xl flex-col items-center gap-3.5 pt-12 pb-10 text-center sm:pt-18">
            <span class="inline-flex h-6 items-center rounded-full bg-accent-wash px-2.5 text-xs leading-4 font-medium text-accent-content">{{ __('Pricing in pesos') }}</span>

            {{-- "Two Stars" is the Free plan's Star limit (nexus.plans.free.stars), in words: change the headline with it. --}}
            <flux:heading level="1" class="text-[2.5rem]! leading-[2.75rem]! font-bold! tracking-[-0.035em]! text-zinc-950! sm:text-[3.5rem]! sm:leading-[3.75rem]! dark:text-white!">
                {{ __('Start free. Go Pro when') }}<br class="max-sm:hidden">
                {{ __('two Stars aren\'t enough.') }}
            </flux:heading>

            <p class="max-w-140 text-base leading-6 text-zinc-600 dark:text-zinc-400">{{ __('Every plan gets every connector and every AI client. Pro lifts the limits on Stars, Connections and tool calls.') }}</p>
        </div>

        <div class="flex w-full flex-col items-center gap-7 pb-10">
            <x-billing-period-picker wire:model.live="period" />

            <div class="grid w-full max-w-220 gap-5 md:grid-cols-2">
                <x-free-plan-card>
                    <x-slot:action>
                        <flux:button
                            :href="auth()->check() ? route('stars.index') : route('auth.sign-in')"
                            class="w-full border-zinc-300! text-zinc-950! shadow-none! dark:border-zinc-600! dark:text-white!"
                            data-start-free
                        >{{ __('Start free') }}</flux:button>
                    </x-slot:action>
                </x-free-plan-card>

                <x-pro-plan-card :period="$this->billingPeriod">
                    <x-slot:action>
                        {{-- Guests are sent to sign in first, and the auth middleware keeps this URL as the intended one, so every sign-in method lands back here with the period picked. --}}
                        <flux:button
                            variant="primary"
                            icon:trailing="arrow-right"
                            :href="route('billing.upgrade', ['period' => $this->billingPeriod->value])"
                            class="w-full"
                            data-start-pro
                        >{{ __('Start with Pro') }}</flux:button>
                    </x-slot:action>
                </x-pro-plan-card>
            </div>
        </div>

        <section class="w-full max-w-220 border-t border-zinc-200 pt-8 pb-6 dark:border-white/10" aria-labelledby="pricing-questions-heading">
            <h2 id="pricing-questions-heading" class="sr-only">{{ __('Questions about pricing') }}</h2>

            <dl class="grid gap-x-12 gap-y-7 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <dt class="text-sm leading-5 font-semibold text-zinc-950 dark:text-white">{{ __('How do I pay?') }}</dt>
                    <dd class="text-sm leading-[1.375rem] text-zinc-600 dark:text-zinc-400">{{ __('On PayMongo\'s secure checkout, with a card, GCash, Maya or QR Ph. Pro doesn\'t renew on its own, so you\'re never charged by surprise.') }}</dd>
                </div>

                <div class="flex flex-col gap-1.5">
                    <dt class="text-sm leading-5 font-semibold text-zinc-950 dark:text-white">{{ __('What happens when Pro ends?') }}</dt>
                    <dd class="text-sm leading-[1.375rem] text-zinc-600 dark:text-zinc-400">{{ __('You\'re back on Free. Every Star and Connection keeps working; you just can\'t add more until you\'re under the Free limits.') }}</dd>
                </div>

                <div class="flex flex-col gap-1.5">
                    <dt class="text-sm leading-5 font-semibold text-zinc-950 dark:text-white">{{ __('What counts as a tool call?') }}</dt>
                    <dd class="text-sm leading-[1.375rem] text-zinc-600 dark:text-zinc-400">{{ __('Each tool your agents call through any of your Stars. Free includes :count a week, reset every Monday; Pro has no weekly limit.', ['count' => number_format(App\Enums\Plan::Free->toolCallsPerWeek() ?? 0)]) }}</dd>
                </div>

                <div class="flex flex-col gap-1.5">
                    <dt class="text-sm leading-5 font-semibold text-zinc-950 dark:text-white">{{ __('Can I get a refund?') }}</dt>
                    <dd class="text-sm leading-[1.375rem] text-zinc-600 dark:text-zinc-400">
                        {{ __('Within 7 days of a payment, if you\'ve changed your mind.') }}
                        <a href="{{ route('legal.refunds') }}" class="underline decoration-zinc-300 underline-offset-[3px] hover:text-zinc-950 hover:decoration-current dark:decoration-white/30 dark:hover:text-white" data-refund-policy-link>{{ __('See the refund policy.') }}</a>
                    </dd>
                </div>
            </dl>
        </section>
    </main>

    <x-public-footer>{{ __('Prices are in Philippine pesos.') }}</x-public-footer>
</div>
