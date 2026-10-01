@php
    /** @var \App\Models\Vault $vault */
    /** @var string|null $token */
    $env = $vault->tokenEnvVar();
@endphp

{{-- Inline styles: Filament ships precompiled CSS, so ad-hoc utility classes wouldn't exist. --}}
<div style="display: grid; gap: 1.5rem; font-size: 0.875rem;">
    @if ($token)
        <div style="display: grid; gap: 0.5rem;">
            <strong>Token</strong>
            @include('filament.vaults.snippet', ['snippet' => $token])
        </div>
    @endif

    <div style="display: grid; gap: 0.5rem;">
        <strong>1. Put the token in your environment</strong>
        <span style="opacity: 0.7;">Add this to your shell profile (e.g. <code>~/.zshrc</code>). Apps opened from the Dock don't read your shell profile; on macOS also run <code>launchctl setenv {{ $env }} …</code> for them.</span>
        @include('filament.vaults.snippet', ['snippet' => "export {$env}=".($token ?? 'nxs_…')])
    </div>

    <div style="display: grid; gap: 1rem;">
        <strong>2. Add the vault to your client</strong>

        @foreach (\App\Support\ClientSetup::for($vault) as $client)
            <div style="display: grid; gap: 0.5rem;">
                <span>
                    {{ $client['client'] }}
                    @if ($client['file'])
                        <span style="opacity: 0.7;">— add to <code>{{ $client['file'] }}</code></span>
                    @endif
                </span>
                @include('filament.vaults.snippet', ['snippet' => $client['snippet']])
            </div>
        @endforeach
    </div>
</div>
