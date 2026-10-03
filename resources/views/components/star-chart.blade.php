{{--
    The picture of Nexus on the welcome and sign-in pages: Connections
    (GitHub, Linear, Notion) flow into a Star, which serves the clients
    (Claude Code, Cursor, Codex), drawn on a night sky. It is always dark,
    in both themes, and fills its box: the drawing scales to the width and
    stays centred, and its rings run out to the edges. An optional caption
    goes in the slot, at the bottom.
--}}
@php
    $catalog = app(\App\Connectors\ConnectorCatalog::class);

    $connections = ['github' => 300, 'linear' => 420, 'notion' => 540];

    $clients = [
        ['name' => 'Claude Code', 'y' => 320, 'width' => 126],
        ['name' => 'Cursor', 'y' => 420, 'width' => 88],
        ['name' => 'Codex', 'y' => 520, 'width' => 86],
    ];

    $sky = [
        [64, 72, 1.2, .5], [212, 138, .9, .35], [388, 64, 1.4, .6], [530, 150, .9, .3], [706, 92, 1.2, .5],
        [760, 230, .9, .35], [48, 250, .9, .3], [300, 226, 1, .4], [640, 640, 1.1, .4], [740, 700, .9, .3],
        [90, 680, 1.1, .35], [500, 700, .9, .3], [250, 640, 1.2, .45], [690, 430, .9, .25], [36, 410, 1, .35],
        [770, 520, 1.1, .4], [580, 330, .9, .3], [190, 470, .9, .25], [460, 580, 1, .3], [120, 330, .8, .3],
        [620, 250, 1, .35], [330, 760, 1.1, .4], [700, 820, .9, .3], [150, 830, 1, .35], [430, 860, .9, .3],
    ];
@endphp

<div {{ $attributes->class('relative isolate flex flex-col overflow-hidden bg-zinc-950 text-white') }} data-star-chart>
    <svg class="absolute inset-0 -z-10 size-full" viewBox="0 0 800 900" preserveAspectRatio="xMidYMid slice" fill="white" aria-hidden="true">
        @foreach ($sky as [$x, $y, $radius, $opacity])
            <circle cx="{{ $x }}" cy="{{ $y }}" r="{{ $radius }}" fill-opacity="{{ $opacity }}" />
        @endforeach
    </svg>

    <div class="flex flex-1 items-center justify-center px-6 py-12">
        <svg class="w-full max-w-172 overflow-visible" viewBox="56 236 688 368" role="img" aria-label="{{ __('Connections to GitHub, Linear and Notion flow into a Star, which Claude Code, Cursor and Codex use.') }}">
            <g fill="none" stroke="white">
                <circle cx="400" cy="420" r="150" stroke-opacity=".06" />
                <circle cx="400" cy="420" r="260" stroke-opacity=".05" />
                <circle cx="400" cy="420" r="370" stroke-opacity=".04" />
                <path d="M160 300C270 300 290 420 356 420M160 420H356M160 540C270 540 290 420 356 420" stroke-opacity=".28" stroke-width="1.2" />
            </g>
            <path d="M444 420C510 420 530 320 596 320M444 420H596M444 420C510 420 530 520 596 520" fill="none" stroke="#6f80f0" stroke-width="1.4" />

            @foreach ($connections as $key => $y)
                <rect x="104.5" y="{{ $y - 27.5 }}" width="55" height="55" rx="13.5" fill="#1b1f27" stroke="#2c313b" />
                <svg x="119" y="{{ $y - 13 }}" width="26" height="26" color="white">{{ $catalog->find($key)?->logo() }}</svg>
            @endforeach

            <circle cx="400" cy="420" r="70" class="fill-accent" fill-opacity=".08" />
            <circle cx="400" cy="420" r="58" class="fill-accent" fill-opacity=".18" />
            <circle cx="400" cy="420" r="48" class="fill-accent" />
            <svg x="385" y="405" width="30" height="30" viewBox="0 0 24 24" fill="white">
                <path d="M12 2.5l2.6 6.2 6.7.6-5.1 4.4 1.5 6.6L12 16.8l-5.7 3.5 1.5-6.6-5.1-4.4 6.7-.6L12 2.5z" />
            </svg>
            <text x="400" y="508" text-anchor="middle" dominant-baseline="central" fill="#a9b4f7" font-size="12" letter-spacing=".72" class="font-mono uppercase">{{ __('Work') }}</text>

            @foreach ($clients as $client)
                <rect x="596.5" y="{{ $client['y'] - 19.5 }}" width="{{ $client['width'] - 1 }}" height="39" rx="19.5" fill="#161a22" stroke="#3a4bc8" />
                <circle cx="613.5" cy="{{ $client['y'] }}" r="3.5" fill="#4ade80" />
                <text x="625" y="{{ $client['y'] }}" dominant-baseline="central" fill="white" font-size="13" class="font-sans font-medium">{{ $client['name'] }}</text>
            @endforeach
        </svg>
    </div>

    @if ($slot->hasActualContent())
        <div class="px-10 pb-12 xl:px-16 xl:pb-16">
            {{ $slot }}
        </div>
    @endif
</div>
