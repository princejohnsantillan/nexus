<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>{{ filled($title ?? null) ? $title.' · '.config('app.name') : config('app.name') }}</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<link rel="preload" href="{{ Vite::asset('resources/fonts/inter/InterVariable-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
@fonts
@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
