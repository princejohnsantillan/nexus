{{--
    The behaviour hints a server declared true for a tool, as small badges,
    or a dash when it declared none: read-only green, destructive red,
    open-world amber, idempotent neutral. Hints declared false or not stated
    show nothing; the Connection's Tools page shows all four in full.
--}}
@props([
    'tool',
])

@php
$badge = 'inline-flex h-5.5 items-center rounded-md px-1.5 text-xs font-medium whitespace-nowrap';
@endphp

@if ($tool->read_only || $tool->destructive || $tool->idempotent || $tool->open_world)
    <div {{ $attributes->class('flex flex-wrap gap-1') }}>
        @if ($tool->read_only)
            <span class="{{ $badge }} bg-success-wash text-success">{{ __('Read-only') }}</span>
        @endif
        @if ($tool->destructive)
            <span class="{{ $badge }} bg-danger-wash text-danger">{{ __('Destructive') }}</span>
        @endif
        @if ($tool->idempotent)
            <span class="{{ $badge }} bg-zinc-50 text-zinc-600 ring-1 ring-zinc-200 ring-inset dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10">{{ __('Idempotent') }}</span>
        @endif
        @if ($tool->open_world)
            <span class="{{ $badge }} bg-warning-wash text-warning">{{ __('Open-world') }}</span>
        @endif
    </div>
@else
    <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500" {{ $attributes }}>&mdash;</flux:text>
@endif
