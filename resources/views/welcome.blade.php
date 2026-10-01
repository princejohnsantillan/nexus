<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} · One MCP server for every AI tool</title>
    <style>
        :root {
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #475569;
            --border: #e2e8f0;
            --accent: #4f46e5;
            --error-bg: #fef2f2;
            --error-text: #991b1b;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b1020;
                --card: #111827;
                --text: #f1f5f9;
                --muted: #94a3b8;
                --border: #1f2937;
                --accent: #818cf8;
                --error-bg: #3f1d1d;
                --error-text: #fecaca;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px 16px;
            background: var(--bg);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.5;
        }

        main {
            width: 100%;
            max-width: 440px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px;
        }

        h1 { margin: 0 0 8px; font-size: 1.75rem; letter-spacing: -0.02em; }
        p { margin: 0 0 24px; color: var(--muted); }

        .buttons { display: grid; gap: 12px; }

        .button {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            text-decoration: none;
            font-weight: 600;
        }

        .button:hover { border-color: var(--accent); }
        .button svg { width: 20px; height: 20px; fill: currentColor; }

        .primary { background: var(--accent); border-color: var(--accent); color: #fff; }

        .error {
            margin-bottom: 20px;
            padding: 12px 14px;
            border-radius: 10px;
            background: var(--error-bg);
            color: var(--error-text);
            font-size: 0.9rem;
        }

        ul { margin: 24px 0 0; padding-left: 18px; color: var(--muted); font-size: 0.9rem; }
    </style>
</head>
<body>
    <main>
        <h1>{{ config('app.name') }}</h1>
        <p>Connect your MCP servers once. Every AI tool you use reaches all of them through one endpoint.</p>

        @if (session('error'))
            <div class="error" role="alert">{{ session('error') }}</div>
        @endif

        <div class="buttons">
            @auth
                <a class="button primary" href="{{ \Filament\Facades\Filament::getPanel('app')->getUrl() }}">Open dashboard</a>
            @else
                <a class="button" href="{{ route('auth.redirect', 'github') }}">
                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
                    Continue with GitHub
                </a>
                <a class="button" href="{{ route('auth.redirect', 'google') }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.35 11.1H12v2.98h5.35c-.23 1.4-1.66 4.1-5.35 4.1-3.22 0-5.85-2.67-5.85-5.96S8.78 6.26 12 6.26c1.83 0 3.06.78 3.76 1.45l2.56-2.47C16.68 3.7 14.55 2.75 12 2.75 6.9 2.75 2.75 6.9 2.75 12S6.9 21.25 12 21.25c5.34 0 8.88-3.75 8.88-9.04 0-.6-.07-1.06-.15-1.51z"/></svg>
                    Continue with Google
                </a>
            @endauth
        </div>

        <ul>
            <li>Sign in to Slack, Linear, Metabase, Sentry and more, once.</li>
            <li>Group them into vaults, and choose exactly which tools each vault exposes.</li>
            <li>Point Claude, Codex, Cursor or Grok at a vault's URL.</li>
        </ul>
    </main>
</body>
</html>
