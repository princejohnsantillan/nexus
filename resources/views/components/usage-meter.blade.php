{{--
    How much of a plan's limit an account uses, for the Billing page's
    usage row: the label, the count "of" the limit and a bar, amber with
    "· limit reached" at the limit and Atlas blue below it. A null limit
    (Pro) reads "of unlimited" with "No limit on Pro" where the bar goes.
--}}
@props([
    'label',
    'used',
    'limit' => null,
])

@php
$isAtLimit = $limit !== null && $used >= $limit;
$percent = $limit === null || $limit < 1 ? 100 : min(100, round($used / $limit * 100));
@endphp

<div {{ $attributes->class('flex min-w-0 flex-col gap-2.5 px-4 py-5 sm:px-6') }} data-usage-meter @if ($isAtLimit) data-at-limit @endif>
    <p class="text-[13px] leading-4 text-zinc-600 dark:text-zinc-400">{{ $label }}</p>

    <p class="flex flex-wrap items-baseline gap-x-1.5">
        <span @class([
            'text-2xl leading-8 font-semibold tracking-tight tabular-nums',
            'text-zinc-950 dark:text-white' => ! $isAtLimit,
            'text-warning' => $isAtLimit,
        ])>{{ number_format($used) }}</span>

        <span class="text-[13px] leading-4 text-zinc-600 dark:text-zinc-400">
            @if ($limit === null)
                {{ __('of unlimited') }}
            @elseif ($isAtLimit)
                {{ __('of :limit · limit reached', ['limit' => number_format($limit)]) }}
            @else
                {{ __('of :limit', ['limit' => number_format($limit)]) }}
            @endif
        </span>
    </p>

    @if ($limit === null)
        {{-- As tall as the bar it stands in for, so meters with and without a limit line up. --}}
        <p class="h-1 text-xs leading-1 text-zinc-500 dark:text-zinc-400">{{ __('No limit on Pro') }}</p>
    @else
        <div class="h-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10" role="progressbar" aria-label="{{ $label }}" aria-valuemin="0" aria-valuemax="{{ $limit }}" aria-valuenow="{{ min($used, $limit) }}">
            <div @class([
                'h-full rounded-full',
                'bg-accent' => ! $isAtLimit,
                'bg-warning' => $isAtLimit,
            ]) style="width: {{ $percent }}%"></div>
        </div>
    @endif
</div>
