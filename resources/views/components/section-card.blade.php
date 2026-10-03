{{--
    One section of a page, in a card: a heading and description, with an
    optional aside (such as a button) on the right, the body, and an
    optional footer with a hint on the left and actions on the right.
    Pass as="form" with wire:submit to make the whole card the form, so a
    submit button in the footer submits it. danger tints the card for a
    destructive action; <x-danger-card> is the shorthand for it.
--}}
@props([
    'heading',
    'description' => null,
    'as' => 'section',
    'danger' => false,
])

@php
$headingId = $attributes->get('id', Str::slug($heading)).'-heading';
@endphp

<{{ $as }} {{ $attributes->class([
    'overflow-hidden rounded-xl border bg-white dark:bg-white/[4%]',
    'border-zinc-200 dark:border-white/10' => ! $danger,
    'border-danger-rule' => $danger,
]) }} aria-labelledby="{{ $headingId }}">
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3 px-4 pt-5 sm:px-6 {{ $slot->hasActualContent() ? '' : 'pb-5' }}">
        <div class="min-w-0">
            <flux:heading size="lg" level="2" :id="$headingId">{{ $heading }}</flux:heading>

            @if (filled($description))
                <flux:text class="mt-1">{{ $description }}</flux:text>
            @endif
        </div>

        @isset($aside)
            <div class="flex shrink-0 items-center gap-2">
                {{ $aside }}
            </div>
        @endisset
    </div>

    @if ($slot->hasActualContent())
        <div class="px-4 pt-5 pb-6 sm:px-6">
            {{ $slot }}
        </div>
    @endif

    @if (isset($hint) || isset($actions))
        <div @class([
            'flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-t px-4 py-3 sm:px-6',
            'border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-black/15' => ! $danger,
            'border-danger-rule bg-danger-wash' => $danger,
        ])>
            <flux:text @class(['min-w-0', 'text-danger!' => $danger])>{{ $hint ?? '' }}</flux:text>

            @isset($actions)
                <div class="ms-auto flex flex-wrap items-center justify-end gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif
</{{ $as }}>
