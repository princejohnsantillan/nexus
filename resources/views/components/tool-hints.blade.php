{{--
    The behaviour hints a server declared true for a tool, as small badges,
    or a dash when it declared none. Hints declared false or not stated show
    nothing; the Connection's Tools page shows all four in full.
--}}
@props([
    'tool',
])

@if ($tool->read_only || $tool->destructive || $tool->idempotent || $tool->open_world)
    <div {{ $attributes->class('flex flex-wrap gap-1') }}>
        @if ($tool->read_only)
            <flux:badge size="sm" color="green">{{ __('Read-only') }}</flux:badge>
        @endif
        @if ($tool->destructive)
            <flux:badge size="sm" color="red">{{ __('Destructive') }}</flux:badge>
        @endif
        @if ($tool->idempotent)
            <flux:badge size="sm">{{ __('Idempotent') }}</flux:badge>
        @endif
        @if ($tool->open_world)
            <flux:badge size="sm">{{ __('Open-world') }}</flux:badge>
        @endif
    </div>
@else
    <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500" {{ $attributes }}>&mdash;</flux:text>
@endif
