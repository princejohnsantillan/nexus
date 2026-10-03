{{--
    The bar that floats at the bottom of a page while it has unsaved
    changes: "Unsaved changes", what saving would do, and Discard and Save
    changes. Render it inside the page's form only while something is
    unsaved: Save changes submits the form, and Discard calls the
    component's `discard` action (or the one you name). When saving fails
    validation, it says so instead and brings the first field in error into
    view. It is dark in both themes: its `dark` class gives the Flux buttons
    inside their dark look.

    <x-unsaved-changes-bar :consequences="['Adding DeepWiki turns on its 3 read-only tools']" />
--}}
@props([
    'consequences' => [],
    'discard' => 'discard',
])

<div class="pointer-events-none fixed inset-x-0 bottom-0 z-10 flex justify-center p-4 sm:pb-6 lg:start-62">
    <div {{ $attributes->class('dark pointer-events-auto flex w-full max-w-4xl flex-wrap items-center gap-x-4 gap-y-1 rounded-xl bg-zinc-950 py-2 ps-4.5 pe-2 shadow-[0_16px_40px_-10px_rgb(14_17_22/0.45),0_2px_6px_rgb(14_17_22/0.15)] ring-1 ring-white/10 transition duration-200 starting:translate-y-3 starting:opacity-0 sm:w-auto') }} role="region" aria-label="{{ __('Unsaved changes') }}" data-unsaved-changes-bar>
        <div class="flex w-full min-w-0 flex-wrap items-center gap-x-4 gap-y-0.5 py-1 sm:w-auto sm:flex-1">
            <p class="flex shrink-0 items-center gap-2.5 text-sm font-semibold text-white">
                <span class="size-2 shrink-0 rounded-full bg-warning"></span>
                {{ __('Unsaved changes') }}
            </p>

            @if ($errors->isNotEmpty())
                <p class="min-w-0 text-sm text-danger" x-init="$nextTick(() => { let field = $el.closest('form')?.querySelector('[aria-invalid=true], [data-flux-error]:not(.hidden)'); field?.scrollIntoView({ block: 'center' }); field?.focus({ preventScroll: true }) })" data-unsaved-changes-errors>{{ __('Fix the fields marked in red to save.') }}</p>
            @elseif ($consequences !== [])
                <p class="min-w-0 text-sm text-zinc-400" data-unsaved-changes-consequences>{{ implode(' · ', $consequences) }}</p>
            @endif
        </div>

        <div class="ms-auto flex shrink-0 items-center gap-1">
            <flux:button variant="ghost" size="sm" wire:click="{{ $discard }}">{{ __('Discard') }}</flux:button>
            <flux:button type="submit" size="sm" class="border-transparent! bg-white! text-zinc-950! hover:bg-zinc-200!">{{ __('Save changes') }}</flux:button>
        </div>
    </div>
</div>
