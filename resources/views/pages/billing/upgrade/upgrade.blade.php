{{-- The billing boards' frame: 880 wide, left-aligned, 56px in and 40px down from the main area on wide screens (flux:main pads 32). --}}
<div class="w-full max-w-220 lg:mt-2 lg:mb-8 lg:ms-6">
    <nav aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex items-center gap-2 text-sm leading-[1.125rem]">
            <li><a href="{{ route('billing.index') }}" class="text-zinc-600 underline-offset-4 hover:underline dark:text-zinc-300" wire:navigate>{{ __('Billing') }}</a></li>
            <li class="text-zinc-300 dark:text-white/30" aria-hidden="true">/</li>
            <li class="font-medium text-zinc-950 dark:text-white" aria-current="page">{{ __('Upgrade') }}</li>
        </ol>
    </nav>

    <div class="mt-7 flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex min-w-0 flex-col gap-2">
            <flux:heading level="1" class="text-[3rem]! leading-[3.25rem]! font-bold! tracking-tight text-zinc-950! dark:text-white!">{{ $this->isPro ? __('Extend Pro') : __('Go Pro') }}</flux:heading>

            <p class="max-w-[520px] text-base leading-6 text-zinc-600 dark:text-zinc-400" data-upgrade-lede>
                @if ($this->isPro)
                    {{ __('Adds a :period to Pro: until :current becomes until :new.', [
                        'period' => $this->billingPeriod === App\Enums\BillingPeriod::Year ? __('year') : __('month'),
                        'current' => App\Billing\BillingCalendar::date($this->user->nextProStart()),
                        'new' => App\Billing\BillingCalendar::date($this->user->proUntilAfterPaying($this->billingPeriod)),
                    ]) }}
                @else
                    {{ __('No more counting Stars. Bundle every server you use into as many endpoints as your agents need.') }}
                @endif
            </p>
        </div>

        <x-billing-period-picker wire:model.live="period" class="shrink-0" />
    </div>

    @if ($this->cancelled)
        <flux:callout icon="information-circle" :heading="__('Payment cancelled. Nothing was charged.')" class="mt-7" data-payment-cancelled />
    @endif

    <div class="mt-7 grid gap-5 md:grid-cols-2">
        <x-free-plan-card :current="! $this->isPro" />

        <x-pro-plan-card :period="$this->billingPeriod">
            <x-slot:action>
                @if ($this->paymentsAreSetUp)
                    <flux:button variant="primary" icon:trailing="arrow-right" icon-trailing:variant="outline" class="h-10! w-full [&>[data-flux-icon]]:stroke-[2.4]" wire:click="continueToPayment" data-continue-to-payment>{{ __('Continue to payment') }}</flux:button>
                    <flux:error name="checkout" class="mt-3" />
                @else
                    <flux:callout icon="information-circle" :heading="__('Payments aren\'t set up on this Nexus yet.')" data-payments-not-set-up />
                @endif
            </x-slot:action>
        </x-pro-plan-card>
    </div>

    <div class="mt-8 grid gap-6 sm:grid-cols-3 sm:gap-8">
        @foreach ([
            ['icon' => 'lock-closed', 'heading' => __('Pay on PayMongo'), 'text' => __('You\'ll finish on PayMongo\'s secure checkout with a card, GCash, Maya or QR Ph, then come straight back here.')],
            ['icon' => 'arrow-path', 'heading' => __('No auto-renew'), 'text' => __('Pay for a month or a year at a time. Pro doesn\'t renew on its own; we email you a week before it ends.')],
            ['icon' => 'shield', 'heading' => __('Nothing is deleted'), 'text' => __('If Pro ends, every Star and Connection keeps working. You just can\'t add more past the Free limits.')],
        ] as $note)
            <div class="flex flex-col gap-1.5" wire:key="paying-note-{{ $loop->index }}">
                <h2 class="flex items-center gap-2 text-sm leading-[1.125rem] font-semibold text-zinc-950 dark:text-white">
                    <flux:icon :icon="$note['icon']" class="size-4 stroke-[1.7] text-zinc-600 dark:text-zinc-400" />
                    {{ $note['heading'] }}
                </h2>
                <p class="text-[13px] leading-5 text-zinc-600 dark:text-zinc-400">{{ $note['text'] }}</p>
            </div>
        @endforeach
    </div>
</div>
