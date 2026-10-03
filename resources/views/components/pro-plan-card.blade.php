{{--
    The Pro plan as a card to choose from, priced for the `period` picked
    (an App\Enums\BillingPeriod), from `nexus.plans`. The `action` slot
    (such as a button) sits at the bottom.
--}}
@props([
    'period',
])

@php
$pro = App\Enums\Plan::Pro;
$monthly = $pro->price(App\Enums\BillingPeriod::Month);
$yearly = $pro->price(App\Enums\BillingPeriod::Year);
@endphp

<section {{ $attributes->class('flex min-w-0 flex-col gap-5 rounded-xl border-[1.5px] border-accent bg-white p-6 ring-4 ring-accent-wash sm:p-7 dark:bg-white/[4%]') }} aria-labelledby="pro-plan-heading" data-plan-option="pro">
    <div class="flex items-center justify-between gap-3">
        <h2 id="pro-plan-heading" class="flex items-center gap-2 text-base leading-6 font-semibold text-zinc-950 dark:text-white">
            <flux:icon.star variant="solid" class="size-4 text-accent-content" />
            {{ $pro->label() }}
        </h2>

        <p class="font-mono text-xs leading-4 text-accent-content" data-pro-billed>{{ $period === App\Enums\BillingPeriod::Year ? __('Billed yearly') : __('Billed monthly') }}</p>
    </div>

    <div class="flex flex-col gap-1">
        <p class="flex flex-wrap items-baseline gap-x-1.5" data-pro-price>
            <span class="text-[3rem] leading-[3.25rem] font-bold tracking-tight text-zinc-950 dark:text-white">{{ App\Billing\Pesos::rounded($pro->price($period)) }}</span>
            <span class="text-sm leading-[1.125rem] text-zinc-600 dark:text-zinc-400">{{ $period === App\Enums\BillingPeriod::Year ? __('/ year') : __('/ month') }}</span>
        </p>
        <p class="text-[13px] leading-5 text-zinc-500 dark:text-zinc-400" data-pro-price-note>
            @if ($period === App\Enums\BillingPeriod::Year)
                {{ __('About :monthly a month. :price if you pay monthly.', ['monthly' => App\Billing\Pesos::rounded(intdiv($yearly, 12)), 'price' => App\Billing\Pesos::rounded($monthly)]) }}
            @else
                {{ __(':price a year if you pay monthly. Yearly saves :saving.', ['price' => App\Billing\Pesos::rounded($monthly * 12), 'saving' => App\Billing\Pesos::rounded($pro->yearlySaving())]) }}
            @endif
        </p>
    </div>

    <hr class="border-zinc-200 dark:border-white/10">

    <ul class="flex flex-col gap-3">
        @foreach ([__('Unlimited Stars'), __('Unlimited Connections'), __('Unlimited tool calls')] as $line)
            <li class="flex items-center gap-2.5 text-sm leading-5 font-medium text-zinc-950 dark:text-white">
                <flux:icon.check class="size-4 stroke-[2.7] text-accent-content" />
                {{ $line }}
            </li>
        @endforeach
    </ul>

    @isset($action)
        <div class="mt-auto pt-5">
            {{ $action }}
        </div>
    @endisset
</section>
