<?php

namespace App\Support;

use App\Models\Vault;

/**
 * Setup snippets for connecting each supported client to a vault.
 *
 * Every snippet reads the token from an environment variable, so it never
 * sits in plain text inside a client's config file.
 */
class ClientSetup
{
    /**
     * @return list<array{client: string, file: string|null, snippet: string}>
     */
    public static function for(Vault $vault): array
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
            ['client' => 'Claude Code', 'file' => null, 'snippet' => "claude mcp add-json --scope user {$name} '{$claude}'"],
            ['client' => 'Codex', 'file' => '~/.codex/config.toml', 'snippet' => $toml],
            ['client' => 'Cursor', 'file' => '~/.cursor/mcp.json', 'snippet' => (string) $cursor],
            ['client' => 'Grok', 'file' => '~/.grok/config.toml', 'snippet' => $toml],
        ];
    }
}
