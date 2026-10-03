{{--
    A tool's name as the button that opens its <x-tool-flyout>: it calls
    the page's showToolDetails() with the tool's catalog id, and the flyout
    puts focus back on it when it closes.
--}}
@props([
    'tool',
])

<button
    type="button"
    id="tool-details-trigger-{{ $tool->id }}"
    wire:click="showToolDetails({{ $tool->id }})"
    aria-haspopup="dialog"
    {{ $attributes->class('cursor-pointer rounded-sm text-start decoration-zinc-400 underline-offset-4 hover:underline dark:decoration-zinc-500') }}
>{{ $slot }}</button>
