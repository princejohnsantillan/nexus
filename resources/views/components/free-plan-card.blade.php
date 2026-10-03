{{--
    The Free plan as a card to choose from: its price and what it includes,
    from `nexus.plans`. `current` adds a "Your plan" badge. An optional
    `action` slot (such as a button) sits at the bottom.
--}}
@props([
    'current' => false,
])

@php
$free = App\Enums\Plan::Free;
$includes = [
    trans_choice(':count Star|:count Stars', $free->starLimit() ?? 0, ['count' => number_format($free->starLimit() ?? 0)]),
    trans_choice(':count Connection|:count Connections', $free->connectionLimit() ?? 0, ['count' => number_format($free->connectionLimit() ?? 0)]),
    trans_choice(':count tool call a week|:count tool calls a week', $free->toolCallsPerWeek() ?? 0, ['count' => number_format($free->toolCallsPerWeek() ?? 0)]),
];
@endphp

<section {{ $attributes->class('flex min-w-0 flex-col gap-5 rounded-xl border border-zinc-200 bg-white p-6 sm:p-7 dark:border-white/10 dark:bg-white/[4%]') }} aria-labelledby="free-plan-heading" data-plan-option="free">
    <div class="flex items-center justify-between gap-3">
        <h2 id="free-plan-heading" class="text-base leading-6 font-semibold text-zinc-950 dark:text-white">{{ $free->label() }}</h2>

        @if ($current)
            <span class="inline-flex h-5.5 items-center rounded-md border border-zinc-300 px-2 text-xs leading-4 font-medium text-zinc-600 dark:border-white/20 dark:text-zinc-300" data-current-plan-badge>{{ __('Your plan') }}</span>
        @endif
    </div>

    <div class="flex flex-col gap-1">
        <p class="flex flex-wrap items-baseline gap-x-1.5">
            <span class="text-[3rem] leading-[3.25rem] font-bold tracking-tight text-zinc-950 dark:text-white">{{ App\Billing\Pesos::rounded($free->price(App\Enums\BillingPeriod::Month)) }}</span>
            <span class="text-sm leading-[1.125rem] text-zinc-600 dark:text-zinc-400">{{ __('forever') }}</span>
        </p>
        <p class="text-[13px] leading-5 text-zinc-500 dark:text-zinc-400">{{ __('For trying Nexus with a couple of clients.') }}</p>
    </div>

    <hr class="border-zinc-200 dark:border-white/10">

    <ul class="flex flex-col gap-3">
        @foreach ($includes as $line)
            <li class="flex items-center gap-2.5 text-sm leading-5 text-zinc-950 dark:text-white">
                <flux:icon.check class="size-4 stroke-[2.4] text-zinc-500 dark:text-zinc-400" />
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
