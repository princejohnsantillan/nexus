@props([
    'status',
])

<flux:badge size="sm" :color="$status->color()" {{ $attributes }}>{{ $status->label() }}</flux:badge>
