{{--
    One behaviour hint a server declared for a tool: yes, no, or not stated
    when its annotations don't say. tone colours a yes the way <x-tool-hints>
    colours that hint: success for read-only, danger for destructive and
    warning for open-world; without it a yes is neutral.
--}}
@props([
    'value',
    'tone' => null,
])

@php
$badge = 'inline-flex h-5.5 items-center rounded-md px-1.5 text-xs font-medium whitespace-nowrap';
$neutral = 'bg-zinc-50 text-zinc-600 ring-1 ring-zinc-200 ring-inset dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10';
@endphp

@if ($value === true)
    <span {{ $attributes->class([$badge, match ($tone) {
        'success' => 'bg-success-wash text-success',
        'warning' => 'bg-warning-wash text-warning',
        'danger' => 'bg-danger-wash text-danger',
        default => $neutral,
    }]) }}>{{ __('Yes') }}</span>
@elseif ($value === false)
    <span {{ $attributes->class([$badge, $neutral]) }}>{{ __('No') }}</span>
@else
    <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500" {{ $attributes }}>{{ __('Not stated') }}</flux:text>
@endif
