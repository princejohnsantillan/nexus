<?php

declare(strict_types=1);

namespace App\Stars;

use App\Enums\StarAccessMode;
use App\Models\Star;

/**
 * Copy-paste setup for adding a Star to each MCP client, for the Star's
 * access mode. A client knows the Star as `nexus-{slug}`.
 *
 * In token mode every snippet reads the token from the environment
 * variable `NEXUS_{SLUG}_TOKEN`, so it never sits in plain text in a
 * client's config file. In signed-URL mode the snippets hold only the
 * signed URL, which carries the credential itself.
 *
 * Each entry names the client and gives the snippet, with the config file
 * it goes in, or else the instruction for using it (null for a command to
 * run in a terminal).
 */
final readonly class ClientSetup
{
    /**
     * @return list<array{client: string, file: string|null, instruction: string|null, snippet: string}>
     */
    public function for(Star $star): array
    {
        return match ($star->access_mode) {
            StarAccessMode::Token => $this->withToken($star),
            StarAccessMode::SignedUrl => $this->withUrl($star),
        };
    }

    /**
     * The name clients know the Star by.
     */
    public static function serverName(Star $star): string
    {
        return 'nexus-'.$star->slug;
    }

    /**
     * The environment variable clients read the Star's token from.
     */
    public static function tokenVariable(Star $star): string
    {
        return 'NEXUS_'.strtoupper(str_replace('-', '_', $star->slug)).'_TOKEN';
    }

    /**
     * @return list<array{client: string, file: string|null, instruction: string|null, snippet: string}>
     */
    private function withToken(Star $star): array
    {
        $name = self::serverName($star);
        $url = $star->endpointUrl();
        $variable = self::tokenVariable($star);

        $claudeCode = $this->json([
            'type' => 'http',
            'url' => $url,
            'headers' => ['Authorization' => 'Bearer ${'.$variable.'}'],
        ]);

        $cursor = $this->json(['mcpServers' => [$name => [
            'url' => $url,
            'headers' => ['Authorization' => 'Bearer ${env:'.$variable.'}'],
        ]]], JSON_PRETTY_PRINT);

        $toml = <<<TOML
            [mcp_servers.{$name}]
            url = "{$url}"
            bearer_token_env_var = "{$variable}"
            TOML;

        return [
            ['client' => 'Claude Code', 'file' => null, 'instruction' => null, 'snippet' => "claude mcp add-json --scope user {$name} '{$claudeCode}'"],
            ['client' => 'Codex', 'file' => '~/.codex/config.toml', 'instruction' => null, 'snippet' => $toml],
            ['client' => 'Cursor', 'file' => '~/.cursor/mcp.json', 'instruction' => null, 'snippet' => $cursor],
            ['client' => 'Grok', 'file' => '~/.grok/config.toml', 'instruction' => null, 'snippet' => $toml],
        ];
    }

    /**
     * Setup with nothing but the URL a client adds (`Star::clientUrl()`),
     * which carries whatever it needs to authenticate. claude.ai takes only
     * a URL, so it can be set up this way too.
     *
     * @return list<array{client: string, file: string|null, instruction: string|null, snippet: string}>
     */
    private function withUrl(Star $star): array
    {
        $name = self::serverName($star);
        $url = $star->clientUrl();

        $cursor = $this->json(['mcpServers' => [$name => ['url' => $url]]], JSON_PRETTY_PRINT);

        $toml = <<<TOML
            [mcp_servers.{$name}]
            url = "{$url}"
            TOML;

        return [
            ['client' => 'claude.ai', 'file' => null, 'instruction' => __('Open Settings → Connectors, choose "Add custom connector" and paste this URL:'), 'snippet' => $url],
            ['client' => 'Claude Code', 'file' => null, 'instruction' => null, 'snippet' => "claude mcp add --transport http --scope user {$name} '{$url}'"],
            ['client' => 'Codex', 'file' => '~/.codex/config.toml', 'instruction' => null, 'snippet' => $toml],
            ['client' => 'Cursor', 'file' => '~/.cursor/mcp.json', 'instruction' => null, 'snippet' => $cursor],
            ['client' => 'Grok', 'file' => '~/.grok/config.toml', 'instruction' => null, 'snippet' => $toml],
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value, int $flags = 0): string
    {
        return json_encode($value, $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
