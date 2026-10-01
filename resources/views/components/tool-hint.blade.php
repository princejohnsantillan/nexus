{{--
    One behaviour hint a server declared for a tool: yes, no, or not stated
    when its annotations don't say.
--}}
@props([
    'value',
])

@if ($value === true)
    <flux:badge size="sm" color="green" {{ $attributes }}>{{ __('Yes') }}</flux:badge>
@elseif ($value === false)
    <flux:badge size="sm" color="zinc" {{ $attributes }}>{{ __('No') }}</flux:badge>
@else
    <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500" {{ $attributes }}>{{ __('Not stated') }}</flux:text>
@endif
