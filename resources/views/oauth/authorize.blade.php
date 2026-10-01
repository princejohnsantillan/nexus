@php
    /** @var \Laravel\Passport\Client $client */
    /** @var \App\Models\User $user */
    /** @var \App\Models\Vault|null $vault */
    $ownsVault = $vault !== null && $vault->user_id === $user->getKey();
    $redirectHost = parse_url((string) $request->query('redirect_uri'), PHP_URL_HOST) ?: $request->query('redirect_uri');
    $toolCount = $ownsVault ? app(\App\Mcp\Vaults\VaultToolset::class)->enabled($vault)->count() : 0;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} · Approve access</title>
    <style>
        :root { --bg: #f8fafc; --card: #fff; --text: #0f172a; --muted: #475569; --border: #e2e8f0; --accent: #4f46e5; --warn-bg: #fffbeb; --warn-text: #92400e; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b1020; --card: #111827; --text: #f1f5f9; --muted: #94a3b8; --border: #1f2937; --accent: #818cf8; --warn-bg: #3a2a0a; --warn-text: #fde68a; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--text); font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; line-height: 1.5; }
        main { width: 100%; max-width: 460px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 32px; }
        h1 { margin: 0 0 12px; font-size: 1.35rem; letter-spacing: -0.01em; }
        p { margin: 0 0 16px; color: var(--muted); }
        dl { margin: 0 0 24px; display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; font-size: 0.9rem; }
        dt { color: var(--muted); }
        dd { margin: 0; overflow-wrap: anywhere; }
        .actions { display: flex; gap: 12px; }
        .actions form { flex: 1; }
        button { width: 100%; padding: 11px 16px; border-radius: 10px; font: inherit; font-weight: 600; cursor: pointer; border: 1px solid var(--border); background: transparent; color: var(--text); }
        .approve { background: var(--accent); border-color: var(--accent); color: #fff; }
        .warn { padding: 12px 14px; border-radius: 10px; background: var(--warn-bg); color: var(--warn-text); font-size: 0.9rem; margin-bottom: 20px; }
    </style>
</head>
<body>
    <main>
        @if ($ownsVault)
            <h1>Allow {{ $client->name }} to use your “{{ $vault->name }}” vault?</h1>
            <p>It will be able to call the {{ $toolCount }} {{ \Illuminate\Support\Str::plural('tool', $toolCount) }} switched on in this vault, as you, until you revoke it from the vault's page in Nexus.</p>

            <dl>
                <dt>App</dt><dd>{{ $client->name }}</dd>
                <dt>Returns to</dt><dd>{{ $redirectHost }}</dd>
                <dt>Vault</dt><dd>{{ $vault->name }}</dd>
                <dt>Signed in as</dt><dd>{{ $user->email }}</dd>
            </dl>

            <p style="font-size: 0.85rem;">Only approve if you just added this vault to {{ $client->name }} yourself.</p>
        @else
            <h1>This app isn't connected to one of your vaults</h1>
            <div class="warn" role="alert">{{ $client->name }} registered for a vault that doesn't belong to {{ $user->email }}, or that no longer uses OAuth. Approving would not give it access, so you can only deny.</div>
        @endif

        <div class="actions">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit">Deny</button>
            </form>

            @if ($ownsVault)
                <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                    @csrf
                    <input type="hidden" name="state" value="">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="approve">Approve</button>
                </form>
            @endif
        </div>
    </main>
</body>
</html>
