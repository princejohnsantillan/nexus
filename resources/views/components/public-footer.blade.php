@php
    $links = [
        ['label' => __('Terms'), 'route' => 'legal.terms'],
        ['label' => __('Privacy'), 'route' => 'legal.privacy'],
        ['label' => __('Refunds'), 'route' => 'legal.refunds'],
    ];
@endphp

<footer class="mx-auto flex w-full max-w-5xl flex-col gap-3 px-6 py-8 text-[0.8125rem] leading-5 text-zinc-600 sm:flex-row sm:items-center sm:justify-between dark:text-zinc-400">
    <p>{{ __('Nexus is open source.') }} {{ $slot }}</p>

    <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="{{ __('Legal and source') }}">
        @foreach ($links as $link)
            <a
                href="{{ route($link['route']) }}"
                @class([
                    'hover:text-zinc-950 hover:underline dark:hover:text-white',
                    'text-zinc-950 dark:text-white' => request()->routeIs($link['route']),
                ])
                @if (request()->routeIs($link['route'])) aria-current="page" @endif
            >{{ $link['label'] }}</a>
        @endforeach

        <a href="https://github.com/princejohnsantillan/nexus" target="_blank" rel="noopener noreferrer" class="hover:text-zinc-950 hover:underline dark:hover:text-white">{{ __('GitHub') }}</a>
    </nav>
</footer>
