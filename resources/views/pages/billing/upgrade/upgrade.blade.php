<div class="mx-auto w-full max-w-5xl">
    <nav aria-label="{{ __('Breadcrumb') }}">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('billing.index')" separator="slash" class="*:font-normal *:text-zinc-600 dark:*:text-zinc-300" wire:navigate>{{ __('Billing') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item class="*:text-zinc-950 dark:*:text-white" aria-current="page">{{ __('Upgrade') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
    </nav>

    <div class="mt-7 flex max-w-220 flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
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

    <div class="mt-7 grid max-w-220 gap-5 md:grid-cols-2">
        <x-free-plan-card :current="! $this->isPro" />

        <x-pro-plan-card :period="$this->billingPeriod">
            <x-slot:action>
                {{-- "Continue to payment" takes this callout's place once this Nexus takes payments through PayMongo. --}}
                <flux:callout icon="information-circle" :heading="__('Payments aren\'t set up on this Nexus yet.')" data-payments-not-set-up />
            </x-slot:action>
        </x-pro-plan-card>
    </div>

    <div class="mt-8 grid max-w-220 gap-6 sm:grid-cols-3 sm:gap-8">
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
