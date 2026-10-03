<div class="mx-auto mt-8 flex w-full max-w-120 flex-col gap-6 sm:mt-16" @if ($this->state === 'confirming') wire:poll.2s="check" @endif data-payment="{{ $this->state }}">
    @php
        $payment = $this->payment;
        $state = $this->state;
    @endphp

    <div @class([
        'flex size-12 shrink-0 items-center justify-center rounded-full',
        'bg-success-wash text-success' => $state === 'paid',
        'bg-accent-wash text-accent-content' => $state === 'confirming',
        'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' => in_array($state, ['waiting', 'expired'], true),
    ])>
        @switch ($state)
            @case ('paid')
                <flux:icon.check class="size-6 stroke-[2.2]" />
                @break
            @case ('confirming')
                <flux:icon.spinner class="size-6" />
                @break
            @case ('waiting')
                <flux:icon.clock class="size-6 stroke-[1.8]" />
                @break
            @default
                <flux:icon.x-mark class="size-6 stroke-[1.8]" />
        @endswitch
    </div>

    <div class="flex flex-col gap-2" role="status">
        <flux:heading level="1" class="text-[2rem]! leading-[2.375rem]! font-bold! tracking-tight text-zinc-950! dark:text-white!">
            @switch ($state)
                @case ('paid')
                    {{ $payment->extendedPro() ? __('Pro extended') : __('You\'re on Pro') }}
                    @break
                @case ('confirming')
                    {{ __('Confirming your payment') }}
                    @break
                @case ('waiting')
                    {{ __('Still waiting for PayMongo') }}
                    @break
                @default
                    {{ __('This checkout expired') }}
            @endswitch
        </flux:heading>

        <p class="text-base leading-6 text-zinc-600 dark:text-zinc-400">
            @switch ($state)
                @case ('paid')
                    {{ __('Thanks for paying for Nexus. Create as many Stars and Connections as you need.') }}
                    {{ $payment->receipt_email !== null ? __('PayMongo emailed your receipt to :email.', ['email' => $payment->receipt_email]) : __('PayMongo emailed you a receipt.') }}
                    @break
                @case ('confirming')
                    {{ __('We\'re waiting for PayMongo to confirm it. This usually takes a few seconds and the page updates on its own. You can leave: Pro starts the moment the payment is confirmed.') }}
                    @break
                @case ('waiting')
                    {{ __('PayMongo hasn\'t confirmed the payment yet. Pro starts as soon as it does, whether or not this page is open.') }}
                    @break
                @default
                    {{ __('It closed before a payment went through, so nothing was charged.') }}
            @endswitch
        </p>
    </div>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-white/[4%]" aria-label="{{ __('Receipt') }}" data-receipt>
        <div class="flex items-center justify-between gap-4 px-5 py-4">
            <div class="flex min-w-0 flex-col gap-0.5">
                <p class="text-sm leading-5 font-semibold text-zinc-950 dark:text-white">{{ $payment->itemName() }}</p>
                <p class="text-[13px] leading-4 text-zinc-600 dark:text-zinc-400">
                    @if ($state === 'paid')
                        {{ __('Paid with :method · :date', ['method' => $payment->methodLabel() ?? __('PayMongo'), 'date' => App\Billing\BillingCalendar::date($payment->paid_at ?? $payment->updated_at ?? now())]) }}
                    @elseif ($state === 'expired')
                        {{ __('Not paid') }}
                    @elseif ($payment->methodLabel() !== null)
                        {{ __(':method · waiting for PayMongo', ['method' => $payment->methodLabel()]) }}
                    @else
                        {{ __('Waiting for PayMongo') }}
                    @endif
                </p>
            </div>

            <p class="shrink-0 font-mono text-base leading-5 font-medium text-zinc-950 dark:text-white">{{ App\Billing\Pesos::exact($payment->amount) }}</p>
        </div>

        <div class="flex items-center justify-between gap-4 border-t border-zinc-200 bg-zinc-50 px-5 py-3 text-[13px] leading-4 dark:border-white/10 dark:bg-black/15">
            @if ($state === 'paid' && $payment->pro_until !== null)
                <p class="text-zinc-600 dark:text-zinc-400">{{ __('Pro until') }}</p>
                <p class="font-medium text-zinc-950 dark:text-white" data-receipt-pro-until>{{ App\Billing\BillingCalendar::date($payment->pro_until) }}</p>
            @else
                <p class="text-zinc-600 dark:text-zinc-400">{{ __('Status') }}</p>
                <p @class([
                    'font-medium',
                    'text-accent-content' => $state === 'confirming',
                    'text-zinc-950 dark:text-white' => $state !== 'confirming',
                ]) data-receipt-status>
                    {{ match ($state) {
                        'confirming' => __('Processing'),
                        'waiting' => __('Not confirmed yet'),
                        default => __('Expired'),
                    } }}
                </p>
            @endif
        </div>
    </section>

    <div class="flex flex-wrap items-center gap-2">
        @switch ($state)
            @case ('paid')
                <flux:button variant="primary" class="h-9! px-3.5!" :href="route('stars.index')" wire:navigate>{{ __('Create a Star') }}</flux:button>
                <flux:button class="h-9! px-3.5!" :href="route('billing.index')" wire:navigate>{{ __('View billing') }}</flux:button>
                @break
            @case ('waiting')
                <flux:button variant="primary" class="h-9! px-3.5!" wire:click="checkAgain">{{ __('Check again') }}</flux:button>
                <flux:button class="h-9! px-3.5!" :href="route('billing.index')" wire:navigate>{{ __('Back to billing') }}</flux:button>
                @break
            @case ('expired')
                <flux:button variant="primary" class="h-9! px-3.5!" :href="route('billing.upgrade', ['period' => $payment->period->value])" wire:navigate>{{ __('Try again') }}</flux:button>
                <flux:button class="h-9! px-3.5!" :href="route('billing.index')" wire:navigate>{{ __('Back to billing') }}</flux:button>
                @break
            @default
                <flux:button class="h-9! px-3.5!" :href="route('billing.index')" wire:navigate>{{ __('Back to billing') }}</flux:button>
        @endswitch
    </div>
</div>
