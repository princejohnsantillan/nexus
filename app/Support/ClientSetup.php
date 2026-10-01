<?php

namespace App\Support;

use App\Enums\VaultAuthMode;
use App\Models\Vault;

/**
 * Setup snippets for connecting each supported client to a vault, for the
 * vault's sign-in mode.
 *
 * Token-mode snippets read the token from an environment variable, so it
 * never sits in plain text inside a client's config file.
 */
class ClientSetup
{
    /**
     * @return list<array{client: string, file: string|null, snippet: string|null, note: string|null}>
     */
    public static function for(Vault $vault): array
    {
        return match ($vault->auth_mode) {
            VaultAuthMode::Token => self::withToken($vault),
            VaultAuthMode::SignedUrl => self::withUrl($vault, $vault->signedUrl(), oauth: false),
            VaultAuthMode::OAuth => self::withUrl($vault, $vault->endpointUrl(), oauth: true),
        };
    }

    /**
     * @return list<array{client: string, file: string|null, snippet: string|null, note: string|null}>
     */
    protected static function withToken(Vault $vault): array
    {
        $name = $vault->clientKey();
        $url = $vault->endpointUrl();
        $env = $vault->tokenEnvVar();

        $claude = json_encode([
            'type' => 'http',
            'url' => $url,
            'headers' => ['Authorization' => 'Bearer ${'.$env.'}'],
        ], JSON_UNESCAPED_SLASHES);

        $cursor = json_encode([
            'mcpServers' => [
                $name => [
                    'url' => $url,
                    'headers' => ['Authorization' => 'Bearer ${env:'.$env.'}'],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $toml = <<<TOML
            [mcp_servers.{$name}]
            url = "{$url}"
            bearer_token_env_var = "{$env}"
            TOML;

        return [
            ['client' => 'Claude Code', 'file' => null, 'snippet' => "claude mcp add-json --scope user {$name} '{$claude}'", 'note' => null],
            ['client' => 'Codex', 'file' => '~/.codex/config.toml', 'snippet' => $toml, 'note' => null],
            ['client' => 'Cursor', 'file' => '~/.cursor/mcp.json', 'snippet' => (string) $cursor, 'note' => null],
            ['client' => 'Grok', 'file' => '~/.grok/config.toml', 'snippet' => $toml, 'note' => null],
        ];
    }

    /**
     * Signed-URL and OAuth vaults need nothing but the URL: the first
     * carries its credential, the second sends the client to Nexus to sign in.
     *
     * @return list<array{client: string, file: string|null, snippet: string|null, note: string|null}>
     */
    protected static function withUrl(Vault $vault, string $url, bool $oauth): array
    {
        $name = $vault->clientKey();

        $cursor = json_encode(['mcpServers' => [$name => ['url' => $url]]], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $toml = <<<TOML
            [mcp_servers.{$name}]
            url = "{$url}"
            TOML;

        return [
            [
                'client' => 'claude.ai (web, desktop and mobile)',
                'file' => null,
                'snippet' => null,
                'note' => 'Settings → Connectors → Add custom connector, and paste the URL.'.($oauth ? ' Claude sends you to Nexus to approve it.' : ''),
            ],
            [
                'client' => 'Claude Code',
                'file' => null,
                'snippet' => "claude mcp add --transport http --scope user {$name} '{$url}'",
                'note' => $oauth ? 'Then run /mcp in Claude Code and choose Authenticate.' : null,
            ],
            [
                'client' => 'Codex',
                'file' => '~/.codex/config.toml',
                'snippet' => $toml,
                'note' => $oauth ? "Then run: codex mcp login {$name}" : null,
            ],
            [
                'client' => 'Cursor',
                'file' => '~/.cursor/mcp.json',
                'snippet' => (string) $cursor,
                'note' => $oauth ? 'Then choose "Needs login" next to it in Cursor\'s MCP settings.' : null,
            ],
            [
                'client' => 'Grok',
                'file' => '~/.grok/config.toml',
                'snippet' => $toml,
                'note' => $oauth ? 'Grok opens Nexus in your browser the first time it connects.' : null,
            ],
        ];
    }
}
