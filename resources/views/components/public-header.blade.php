{{--
    The public pages' header (board P7): the logo, "Pricing" and "Sign in",
    or "Go to Stars" for a signed-in visitor. Pass `current="pricing"` on
    the Pricing page to mark its link; it's a prop rather than the route
    because a Livewire update re-renders the header from another route.
--}}
@props([
    'current' => null,
])

<header class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-6 py-4">
    <x-app-logo :href="route('home')" class="h-7! gap-2.5! [&>div:last-child]:leading-[1.125rem] [&>div:last-child]:font-semibold [&>div:last-child]:tracking-[-0.011em] [&>div:last-child]:text-zinc-950 dark:[&>div:last-child]:text-white" />

    <nav class="flex items-center gap-5" aria-label="{{ __('Main') }}">
        <a
            href="{{ route('pricing') }}"
            @class([
                'text-sm leading-[1.125rem] font-medium hover:text-zinc-950 dark:hover:text-white',
                'text-zinc-950 dark:text-white' => $current === 'pricing',
                'text-zinc-600 dark:text-zinc-400' => $current !== 'pricing',
            ])
            @if ($current === 'pricing') aria-current="page" @endif
        >{{ __('Pricing') }}</a>

        @auth
            <flux:button size="sm" class="rounded-lg! border-zinc-300! text-[13px]! leading-4! text-zinc-950! shadow-none! dark:border-zinc-600! dark:text-white!" :href="route('stars.index')">{{ __('Go to Stars') }}</flux:button>
        @else
            <flux:button size="sm" class="rounded-lg! border-zinc-300! text-[13px]! leading-4! text-zinc-950! shadow-none! dark:border-zinc-600! dark:text-white!" :href="route('auth.sign-in')">{{ __('Sign in') }}</flux:button>
        @endauth
    </nav>
</header>
