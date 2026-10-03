@props([
    'title',
    'updated',
])

@php
    $updatedOn = \Illuminate\Support\Carbon::parse($updated);
@endphp

<div class="flex min-h-screen flex-col">
    <x-public-header />

    <main class="w-full flex-1 px-6 py-12 sm:py-16">
        <article class="mx-auto max-w-170">
            @unless (config()->boolean('nexus.legal.reviewed'))
                <flux:callout icon="pencil-square" color="amber" class="mb-8" data-legal-draft>
                    <flux:callout.text>{{ __('This draft is reviewed before Nexus takes payments.') }}</flux:callout.text>
                </flux:callout>
            @endunless

            <flux:heading level="1" class="text-[2rem]! leading-[2.375rem]! font-bold! tracking-tight text-zinc-950 dark:text-white">{{ $title }}</flux:heading>

            <p class="mt-2 text-[0.8125rem] leading-5 text-zinc-500 dark:text-zinc-400">
                {{ __('Last updated') }} <time datetime="{{ $updatedOn->toDateString() }}">{{ $updatedOn->isoFormat('MMMM D, YYYY') }}</time>
            </p>

            <div class="mt-10 text-base/7 text-zinc-600 dark:text-zinc-300 [&_li]:pl-1 [&_li]:marker:text-zinc-400 [&_strong]:font-semibold [&_strong]:text-zinc-950 dark:[&_strong]:text-white [&>h2]:mt-12 [&>h2]:text-xl/7 [&>h2]:font-semibold [&>h2]:tracking-tight [&>h2]:text-zinc-950 dark:[&>h2]:text-white [&>h2+p]:mt-3 [&>h2+ul]:mt-3 [&>p]:mt-4 [&>p:first-child]:mt-0 [&>ul]:mt-4 [&>ul]:list-disc [&>ul]:space-y-2 [&>ul]:pl-6">
                {{ $slot }}
            </div>
        </article>
    </main>

    <x-public-footer />
</div>
