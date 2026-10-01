{{--
    A block of code or config to copy, such as a client's setup snippet,
    with a button that copies it exactly as shown.
--}}
@props([
    'snippet',
])

<div {{ $attributes->class('relative') }} x-data="{ copied: false }">
    <pre x-ref="snippet" class="whitespace-pre-wrap wrap-anywhere rounded-lg border border-zinc-200 bg-zinc-50 p-4 pe-12 font-mono text-sm text-zinc-800 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">{{ $snippet }}</pre>

    <div class="absolute end-2 top-2">
        <flux:button
            size="sm"
            variant="subtle"
            square
            x-on:click="navigator.clipboard.writeText($refs.snippet.textContent).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
            x-bind:data-copied="copied"
            :aria-label="__('Copy to clipboard')"
        >
            <flux:icon.clipboard-document-check variant="mini" class="hidden [[data-copied]>&]:block" />
            <flux:icon.clipboard-document variant="mini" class="block [[data-copied]>&]:hidden" />
        </flux:button>
    </div>
</div>
