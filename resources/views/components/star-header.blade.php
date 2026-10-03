{{--
    The top of every Star page: a breadcrumb back to the Stars, the Star's
    name with its access mode and its description, the URL clients add
    (the signed URL in signed-URL mode) with a Copy button, and the row of
    links to its sub-pages. `current` names the page being shown.
--}}
@props([
    'star',
    'current',
])

<div>
    <nav aria-label="{{ __('Breadcrumb') }}">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('stars.index')" separator="slash" class="*:font-normal *:text-zinc-600 dark:*:text-zinc-300" wire:navigate>{{ __('Stars') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item class="min-w-0 *:truncate *:text-zinc-950 dark:*:text-white" aria-current="page">{{ $star->name }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
    </nav>

    <div class="mt-4 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between lg:gap-6">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <flux:heading size="xl" level="1" class="break-all text-[2rem]! leading-tight! font-semibold! tracking-tight">{{ $star->name }}</flux:heading>
                <flux:badge size="sm" class="bg-white! text-zinc-950! ring-1 ring-zinc-300 ring-inset dark:bg-white/5! dark:text-white! dark:ring-white/20" data-star-access-mode>{{ $star->access_mode->label() }}</flux:badge>
            </div>

            @if (filled($star->description))
                <flux:text class="mt-1.5 wrap-anywhere">{{ $star->description }}</flux:text>
            @endif
        </div>

        <div class="flex h-9 w-full min-w-0 shrink-0 items-center gap-2.5 rounded-lg border border-zinc-300 bg-white ps-3 pe-1.5 lg:w-auto lg:max-w-sm dark:border-white/15 dark:bg-white/5" x-data="{ copied: false }" data-star-endpoint>
            <span x-ref="url" class="min-w-0 flex-1 truncate font-mono text-[13px] text-zinc-950 dark:text-white" data-star-endpoint-url>{{ $star->clientUrl() }}</span>

            <flux:button
                size="xs"
                variant="filled"
                class="shrink-0"
                x-on:click="navigator.clipboard.writeText($refs.url.textContent).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                x-bind:data-copied="copied"
                :aria-label="$star->access_mode === App\Enums\StarAccessMode::SignedUrl ? __('Copy signed URL') : __('Copy endpoint URL')"
                data-star-endpoint-copy
            >
                <flux:icon.check variant="micro" class="hidden size-3.5 [[data-copied]>&]:block" />
                <flux:icon.document-duplicate variant="micro" class="block size-3.5 [[data-copied]>&]:hidden" />
                <span class="[[data-copied]>&]:hidden">{{ __('Copy') }}</span>
                <span class="hidden [[data-copied]>&]:inline" role="status">{{ __('Copied') }}</span>
            </flux:button>
        </div>
    </div>

    <flux:navbar class="-mb-px mt-4 border-b border-zinc-200 dark:border-white/10" scrollable>
        <flux:navbar.item :href="route('stars.show', $star)" :current="$current === 'overview'" :aria-current="$current === 'overview' ? 'page' : null" :accent="false" wire:navigate>{{ __('Overview') }}</flux:navbar.item>
        <flux:navbar.item :href="route('stars.tools', $star)" :current="$current === 'tools'" :aria-current="$current === 'tools' ? 'page' : null" :accent="false" wire:navigate>{{ __('Tools') }}</flux:navbar.item>
        <flux:navbar.item :href="route('stars.prompts', $star)" :current="$current === 'prompts'" :aria-current="$current === 'prompts' ? 'page' : null" :accent="false" wire:navigate>{{ __('Prompts') }}</flux:navbar.item>
        <flux:navbar.item :href="route('stars.access', $star)" :current="$current === 'access'" :aria-current="$current === 'access' ? 'page' : null" :accent="false" wire:navigate>{{ __('Access') }}</flux:navbar.item>
    </flux:navbar>
</div>
