{{--
    The sidebar's plan card (board P8). On Free, once the week's tool calls
    are used up, it says the Stars refuse calls until Monday, with the calls
    against the weekly limit and an amber border; otherwise it shows the
    user's Stars against the Free limit (amber with a full bar at the limit).
    Both offer "Upgrade to Pro". In Pro's last User::PRO_ENDING_SOON_DAYS
    days it says when Pro ends, with "Extend Pro"; otherwise on Pro, nothing.
    The app layout shows it above the profile, under <x-action-required>.
--}}
@props([
    'user',
])

@if ($user->plan() === App\Enums\Plan::Free)
    @php
        $callLimit = App\Enums\Plan::Free->toolCallsPerWeek();
        $calls = $callLimit === null ? 0 : $user->toolCallsThisWeek();
        $isOutOfCalls = $callLimit !== null && $calls >= $callLimit;

        if ($isOutOfCalls) {
            [$used, $limit] = [$calls, $callLimit];
            $usage = __(':calls / :limit', ['calls' => number_format($calls), 'limit' => number_format($callLimit)]);
        } else {
            [$used, $limit] = [$user->stars()->count(), App\Enums\Plan::Free->starLimit() ?? 0];
            $usage = trans_choice(':stars / :limit Star|:stars / :limit Stars', $limit, ['stars' => $used, 'limit' => $limit]);
        }

        $isAtLimit = $used >= $limit;
    @endphp

    <section {{ $attributes->class([
        'flex flex-col gap-2.5 rounded-xl border bg-white p-3.5 dark:bg-white/[4%]',
        'border-zinc-200 dark:border-white/10' => ! $isOutOfCalls,
        'border-warning-rule' => $isOutOfCalls,
    ]) }} aria-labelledby="plan-card-heading" data-plan-card="{{ $isOutOfCalls ? 'free-calls-used-up' : 'free' }}">
        <div class="flex items-center justify-between gap-2">
            <h2 id="plan-card-heading" class="text-[13px] leading-4 font-semibold text-zinc-950 dark:text-white">{{ __('Free plan') }}</h2>

            <p @class([
                'font-mono text-xs leading-4 tabular-nums',
                'text-zinc-600 dark:text-zinc-400' => ! $isAtLimit,
                'text-warning' => $isAtLimit,
            ]) data-plan-card-usage @if ($isAtLimit) data-at-limit @endif>{{ $usage }}</p>
        </div>

        <div class="h-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10" role="progressbar" aria-label="{{ $isOutOfCalls ? __('Tool calls this week') : __('Stars') }}" aria-valuemin="0" aria-valuemax="{{ $limit }}" aria-valuenow="{{ min($used, $limit) }}">
            <div @class([
                'h-full rounded-full',
                'bg-accent' => ! $isAtLimit,
                'bg-warning' => $isAtLimit,
            ]) style="width: {{ $limit < 1 ? 100 : min(100, round($used / $limit * 100)) }}%"></div>
        </div>

        <p class="text-[13px] leading-5 text-zinc-600 dark:text-zinc-400">
            {{ $isOutOfCalls ? __('Your Stars are refusing tool calls until Monday. Go Pro to lift the limit now.') : __('Go Pro for unlimited Stars, Connections and tool calls.') }}
        </p>

        <flux:button variant="primary" size="sm" class="h-7.5! w-full rounded-lg! text-[13px]!" :href="route('billing.upgrade')" wire:navigate>{{ __('Upgrade to Pro') }}</flux:button>
    </section>
@elseif ($user->isProEndingSoon())
    <section {{ $attributes->class('flex flex-col gap-2.5 rounded-xl border border-warning-rule bg-white p-3.5 dark:bg-white/[4%]') }} aria-labelledby="plan-card-heading" data-plan-card="pro-ending">
        <div class="flex items-center gap-2">
            <flux:icon.clock class="size-3.5 shrink-0 stroke-[2.4] text-warning" />
            <h2 id="plan-card-heading" class="text-[13px] leading-4 font-semibold text-zinc-950 dark:text-white">{{ trans_choice('Pro ends in :count day|Pro ends in :count days', $user->proDaysLeft() ?? 0) }}</h2>
        </div>

        <p class="text-[13px] leading-5 text-zinc-600 dark:text-zinc-400">{{ __('Extend it to keep adding Stars and Connections past the Free limits.') }}</p>

        <flux:button size="sm" class="h-7.5! w-full rounded-lg! text-[13px]!" :href="route('billing.upgrade')" wire:navigate>{{ __('Extend Pro') }}</flux:button>
    </section>
@endif
