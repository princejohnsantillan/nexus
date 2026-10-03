{{--
    The frame of the sign-in pages (boards S1–S3): the logo, the page's own
    content in the middle of the left column with the legal line under it
    (open source, and agreeing to the Terms and Privacy Policy), and the star
    chart filling the right on wide screens. On a narrow screen the chart
    hides and the column fills the page.
--}}
<div class="flex min-h-screen">
    <div class="flex w-full flex-col px-6 py-8 sm:px-14 sm:py-10 lg:w-4/9 lg:max-w-160 lg:shrink-0">
        <header>
            <x-app-logo :href="route('home')" class="h-7! gap-2.5! [&>div:last-child]:leading-[1.125rem] [&>div:last-child]:font-semibold [&>div:last-child]:tracking-[-0.011em] [&>div:last-child]:text-zinc-950 dark:[&>div:last-child]:text-white" />
        </header>

        <main class="flex flex-1 flex-col justify-center py-12">
            <div class="mx-auto w-full max-w-95">
                {{ $slot }}
            </div>
        </main>

        <footer>
            <p class="text-[0.8125rem] leading-5 text-zinc-600 dark:text-zinc-400">
                {{ __('Nexus is') }}
                <a href="https://github.com/princejohnsantillan/nexus" target="_blank" rel="noopener noreferrer" class="hover:text-zinc-950 hover:underline dark:hover:text-white">{{ __('open source') }}</a>{{ __('.') }}
                {{ __('By continuing you agree to the') }}
                <a href="{{ route('legal.terms') }}" class="hover:text-zinc-950 hover:underline dark:hover:text-white">{{ __('Terms') }}</a>
                {{ __('and') }}
                <a href="{{ route('legal.privacy') }}" class="hover:text-zinc-950 hover:underline dark:hover:text-white">{{ __('Privacy Policy') }}</a>{{ __('.') }}
            </p>
        </footer>
    </div>

    <x-star-chart class="hidden flex-1 border-s border-zinc-950 lg:flex dark:border-white/10">
        <p class="max-w-130 text-[2rem] leading-[2.375rem] font-bold tracking-tight">{{ __('One endpoint. Every AI client.') }}</p>
        <p class="mt-3 max-w-130 text-sm leading-[1.375rem] text-zinc-400">{{ __('Your servers sign in once. Each Star decides which of their tools Claude Code, Cursor or Codex can call.') }}</p>
    </x-star-chart>
</div>
