{{--
    A block of code, config or a command on a dark panel. Its header names
    the file it goes in, or says "terminal" for a command (or shows your own
    label), and its Copy button copies exactly what the panel shows.
--}}
@props([
    'code',
    'file' => null,
    'label' => null,
])

@php
$title = $file ?? $label ?? __('terminal');
$icon = match (true) {
    $file !== null => 'document-text',
    $label === null => 'command-line',
    default => null,
};
@endphp

<div {{ $attributes->class('overflow-hidden rounded-xl bg-zinc-950 ring-1 ring-zinc-950 dark:ring-white/10') }} x-data="{ copied: false }" data-code-panel>
    <div class="flex items-center justify-between gap-3 border-b border-white/10 py-2 ps-4 pe-2">
        <div class="flex min-w-0 items-center gap-2 text-zinc-400">
            @if ($icon !== null)
                <flux:icon :icon="$icon" variant="micro" class="size-3.5" />
            @endif

            <span class="truncate font-mono text-xs" data-code-panel-title>{{ $title }}</span>
        </div>

        <button
            type="button"
            class="inline-flex h-6.5 shrink-0 items-center gap-1.5 rounded-md bg-white/8 px-2 text-xs font-medium text-zinc-200 hover:bg-white/15"
            x-on:click="navigator.clipboard.writeText($refs.code.textContent).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
            x-bind:data-copied="copied"
            data-code-panel-copy
        >
            <flux:icon.check variant="micro" class="hidden size-3.5 [[data-copied]>&]:block" />
            <flux:icon.document-duplicate variant="micro" class="block size-3.5 [[data-copied]>&]:hidden" />
            <span class="[[data-copied]>&]:hidden">{{ __('Copy') }}</span>
            <span class="hidden [[data-copied]>&]:inline" role="status">{{ __('Copied') }}</span>
        </button>
    </div>

    <pre x-ref="code" class="overflow-x-auto p-4 font-mono text-[13px] leading-5 whitespace-pre-wrap wrap-anywhere text-zinc-200">{{ $code }}</pre>
</div>
