{{--
    The one Save for a page whose sections are edited together: while
    `unsaved`, a dark bar at the bottom of the page says "Unsaved changes",
    lists the `consequences` of saving, and offers Discard and Save changes,
    which call the component's `discard` and `save` actions (or the ones you
    name). When saving fails validation, it says so instead and brings the
    first field in error into view.

    <x-unsaved-changes-bar :unsaved="true" :consequences="['Adding DeepWiki turns on its 3 read-only tools']" />

    Render it always, as the last thing on the page. It sticks to the bottom
    of the window while the page scrolls under it, and takes its own room at
    the end, so it never covers the page's last section. Its status line,
    which screen readers announce as it changes, stays on the page while
    nothing is unsaved. It is dark in both themes: its `dark` class gives
    the Flux buttons inside their dark look.
--}}
@props([
    'unsaved' => false,
    'consequences' => [],
    'save' => 'save',
    'discard' => 'discard',
])

@php
$message = $errors->isNotEmpty() ? __('Fix the fields marked in red to save.') : implode(' · ', $consequences);
@endphp

<div @class(['pointer-events-none sticky bottom-0 z-10 flex justify-center', 'pt-6 pb-4 sm:pb-6' => $unsaved])>
    <p class="sr-only" role="status" data-unsaved-changes-status>{{ $unsaved ? trim(__('Unsaved changes').'. '.$message) : '' }}</p>

    @if ($unsaved)
        <div {{ $attributes->class('dark pointer-events-auto flex w-full max-w-4xl flex-wrap items-center gap-x-4 gap-y-1 rounded-xl bg-zinc-950 py-2 ps-4.5 pe-2 shadow-[0_16px_40px_-10px_rgb(14_17_22/0.45),0_2px_6px_rgb(14_17_22/0.15)] ring-1 ring-white/10 transition duration-200 starting:translate-y-3 starting:opacity-0 sm:w-auto') }} role="region" aria-label="{{ __('Unsaved changes') }}" data-unsaved-changes-bar>
            <div class="flex w-full min-w-0 flex-wrap items-center gap-x-4 gap-y-0.5 py-1 sm:w-auto sm:flex-1" aria-hidden="true">
                <p class="flex shrink-0 items-center gap-2.5 text-sm font-semibold text-white">
                    <span class="size-2 shrink-0 rounded-full bg-warning"></span>
                    {{ __('Unsaved changes') }}
                </p>

                @if ($errors->isNotEmpty())
                    <p class="min-w-0 text-sm text-danger" x-init="$nextTick(() => { let field = $wire.$el.querySelector('[aria-invalid=true], [data-flux-error]:not(.hidden)'); field?.scrollIntoView({ block: 'center' }); field?.focus({ preventScroll: true }) })" data-unsaved-changes-errors>{{ $message }}</p>
                @elseif (filled($message))
                    <p class="min-w-0 text-sm text-zinc-400" data-unsaved-changes-consequences>{{ $message }}</p>
                @endif
            </div>

            <div class="ms-auto flex shrink-0 items-center gap-1">
                <flux:button variant="ghost" size="sm" wire:click="{{ $discard }}">{{ __('Discard') }}</flux:button>
                <flux:button size="sm" class="border-transparent! bg-white! text-zinc-950! hover:bg-zinc-200!" wire:click="{{ $save }}">{{ __('Save changes') }}</flux:button>
            </div>
        </div>
    @endif
</div>
