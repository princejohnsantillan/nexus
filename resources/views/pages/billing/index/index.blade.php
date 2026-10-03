<div class="mx-auto w-full max-w-5xl">
    @php
        $isPro = $this->plan === App\Enums\Plan::Pro;
        $isEndingSoon = $this->user->isProEndingSoon();
        $daysLeft = $this->user->proDaysLeft();
    @endphp

    <div class="flex max-w-220 flex-col gap-1.5">
        <flux:heading level="1" class="text-[2rem]! leading-[2.375rem]! font-bold! tracking-tight text-zinc-950! dark:text-white!">{{ __('Billing') }}</flux:heading>
        <p class="text-sm leading-[1.375rem] text-zinc-600 dark:text-zinc-400">{{ __('Your plan, what it covers and what you\'ve paid. Payments go through PayMongo; Nexus never sees your card.') }}</p>
    </div>

    <section class="mt-8 max-w-220 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-white/[4%]" aria-labelledby="current-plan-heading" data-current-plan="{{ $isEndingSoon ? 'pro-ending' : $this->plan->value }}">
        <div class="flex flex-wrap items-start justify-between gap-6 p-4 sm:p-6">
            <div class="flex min-w-0 flex-col gap-1.5">
                <h2 id="current-plan-heading" class="text-xs leading-4 font-medium tracking-[0.06em] text-zinc-500 uppercase dark:text-zinc-400">{{ __('Current plan') }}</h2>

                <div class="flex flex-wrap items-baseline gap-x-2.5 gap-y-1">
                    <p class="text-2xl leading-8 font-bold tracking-tight text-zinc-950 dark:text-white">{{ $this->plan->label() }}</p>

                    @if ($isPro)
                        {{-- "Paid monthly" or "Paid yearly" goes here, from the latest paid payment, once Nexus records payments. --}}

                        <span @class([
                            'inline-flex h-5.5 items-center gap-1.25 self-center rounded-full px-2 text-xs leading-4 font-medium whitespace-nowrap',
                            'bg-success-wash text-success' => ! $isEndingSoon,
                            'bg-warning-wash text-warning' => $isEndingSoon,
                        ]) data-plan-status>
                            <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
                            {{ $isEndingSoon ? trans_choice('Ends in :count day|Ends in :count days', $daysLeft) : __('Active') }}
                        </span>
                    @else
                        <p class="text-sm leading-[1.125rem] text-zinc-600 dark:text-zinc-400">{{ __(':price / month', ['price' => App\Billing\Pesos::rounded($this->plan->price(App\Enums\BillingPeriod::Month))]) }}</p>
                    @endif
                </div>

                @if ($isPro)
                    <p class="text-sm leading-[1.375rem] text-zinc-600 dark:text-zinc-400" data-pro-until>
                        @if ($isEndingSoon)
                            {{ __('Pro until :date · then you\'re back on Free', ['date' => App\Billing\BillingCalendar::date($this->user->pro_until)]) }}
                        @else
                            {{ trans_choice('Pro until :date · :count day left|Pro until :date · :count days left', $daysLeft, ['date' => App\Billing\BillingCalendar::date($this->user->pro_until)]) }}
                        @endif
                    </p>
                @endif
            </div>

            @if (! $isPro)
                <flux:button variant="primary" class="h-9! px-3.5!" :href="route('billing.upgrade')" wire:navigate>{{ __('Upgrade to Pro') }}</flux:button>
            @elseif ($isEndingSoon)
                <flux:button variant="primary" class="h-9! px-3.5!" :href="route('billing.upgrade')" wire:navigate>{{ __('Extend Pro') }}</flux:button>
            @else
                <flux:button class="h-9! px-3.5!" :href="route('billing.upgrade')" wire:navigate>{{ __('Extend Pro') }}</flux:button>
            @endif
        </div>

        {{-- The usage meters, side by side; a third meter (tool calls this week) slots in after these. --}}
        <div class="grid divide-y divide-zinc-200 border-t border-zinc-200 sm:auto-cols-fr sm:grid-flow-col sm:divide-x sm:divide-y-0 dark:divide-white/10 dark:border-white/10">
            <x-usage-meter :label="__('Stars')" :used="$this->starCount" :limit="$this->plan->starLimit()" />
            <x-usage-meter :label="__('Connections')" :used="$this->connectionCount" :limit="$this->plan->connectionLimit()" />
        </div>

        <div @class([
            'border-t px-4 py-3.5 text-[13px] leading-5 sm:px-6',
            'border-zinc-200 bg-zinc-50 text-zinc-600 dark:border-white/10 dark:bg-black/15 dark:text-zinc-400' => ! $isEndingSoon,
            'border-warning-rule bg-warning-wash text-warning' => $isEndingSoon,
        ]) data-current-plan-footer>
            @if (! $isPro)
                {{ __('Free forever. Tool calls reset every Monday. Stars and Connections over a limit keep working; you just can\'t add more.') }}
            @elseif ($isEndingSoon)
                {{ __('Extend before :date to keep unlimited Stars and Connections. If Pro ends nothing is deleted; you just can\'t add more past the Free limits.', ['date' => App\Billing\BillingCalendar::shortDate($this->user->pro_until)]) }}
            @else
                {{ __('Pro doesn\'t renew on its own. We\'ll email you a week before it ends, and extending adds to the time you have left.') }}
            @endif
        </div>
    </section>

    <section class="mt-8 max-w-220 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-white/[4%]" aria-labelledby="payments-heading" data-payments>
        <div class="flex flex-col gap-1 px-4 pt-6 sm:px-6">
            <h2 id="payments-heading" class="text-base leading-6 font-semibold text-zinc-950 dark:text-white">{{ __('Payments') }}</h2>
            <p class="text-sm leading-[1.375rem] text-zinc-600 dark:text-zinc-400">{{ __('Every payment you\'ve made. PayMongo emails you a receipt for each one.') }}</p>
        </div>

        {{-- Once Nexus records payments, the paid ones are listed here as a table, newest first, and this empty state stays for none. --}}
        <div class="px-4 pt-5 pb-6 sm:px-6">
            <x-empty-state compact icon="receipt" :heading="__('No payments yet')">
                {{ $isPro ? __('Receipts show up here once you pay for Pro.') : __('You\'re on Free, so there\'s nothing to pay. Receipts show up here once you go Pro.') }}
            </x-empty-state>
        </div>
    </section>
</div>
