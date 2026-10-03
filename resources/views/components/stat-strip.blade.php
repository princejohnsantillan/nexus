{{--
    A row of <x-stat-tile>s in one card, divided by hairlines. The tiles
    stack on a narrow screen.
--}}
<div {{ $attributes->class('grid divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 bg-white sm:auto-cols-fr sm:grid-flow-col sm:divide-x sm:divide-y-0 dark:divide-white/10 dark:border-white/10 dark:bg-white/[4%]') }}>
    {{ $slot }}
</div>
