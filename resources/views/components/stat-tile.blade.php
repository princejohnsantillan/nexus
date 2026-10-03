{{--
    One figure: a label, its value, optional secondary text beside the value
    (e.g. a rate) and an optional sparkline of recent values, oldest first.
    tone colours the value for a status: success, warning or danger. Put
    tiles in an <x-stat-strip>, or use one on its own.
--}}
@props([
    'label',
    'value',
    'secondary' => null,
    'spark' => null,
    'tone' => null,
])

<div {{ $attributes->class('flex min-w-0 flex-col gap-1.5 px-4.5 py-4') }} data-stat-tile>
    <flux:text class="truncate">{{ $label }}</flux:text>

    <div class="flex items-end justify-between gap-2">
        <div class="flex min-w-0 items-baseline gap-2">
            <span @class([
                'text-2xl font-semibold tracking-tight tabular-nums',
                'text-zinc-950 dark:text-white' => $tone === null,
                'text-success' => $tone === 'success',
                'text-warning' => $tone === 'warning',
                'text-danger' => $tone === 'danger',
            ])>{{ $value }}</span>

            @if (filled($secondary))
                <flux:text class="truncate">{{ $secondary }}</flux:text>
            @endif
        </div>

        @if (filled($spark))
            <x-sparkline :values="$spark" class="mb-1" />
        @endif
    </div>
</div>
