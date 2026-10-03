<?php

declare(strict_types=1);

namespace App\Stars;

use App\Enums\McpClient;
use App\Enums\StarAccessMode;
use App\Models\Star;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Copy-paste setup for adding a Star to an MCP client, for the Star's
 * access mode. A client knows the Star as `nexus-{slug}`.
 *
 * In token mode every snippet reads the token from the environment
 * variable `NEXUS_{SLUG}_TOKEN`, so it never sits in plain text in a
 * client's config file. In signed-URL mode the snippets hold only the
 * signed URL, which carries the credential itself. In OAuth mode they hold
 * only the endpoint URL, and each client then signs in to Nexus, which is
 * its login step.
 *
 * A setup gives the snippet, with the config file it goes in, or else the
 * instruction for using it (null for a command to run in a terminal), and
 * in OAuth mode how to sign in afterwards: an instruction, with a command
 * to run when there is one.
 *
 * @phpstan-type Setup array{file: string|null, instruction: string|null, snippet: string, login: array{instruction: string, snippet: string|null}|null}
 */
final readonly class ClientSetup
{
    /**
     * The shell profile the setup suggests for the token's environment variable.
     */
    public const string SHELL_PROFILE = '~/.zshrc';

    /**
     * The setup for one client. The client must be able to reach the Star
     * in its access mode (`McpClient::supports()`).
     *
     * @return Setup
     */
    public function for(Star $star, McpClient $client): array
    {
        return match ($star->access_mode) {
            StarAccessMode::Token => $this->withToken($star, $client),
            StarAccessMode::SignedUrl => $this->withUrl($star, $client),
            StarAccessMode::OAuth => $this->withOAuth($star, $client),
        };
    }

    /**
     * The setup as an instruction an agent can follow to add the Star to
     * the client: the endpoint, then each step with its file or command
     * and snippet. It holds no credential beyond what the snippets hold:
     * in token mode the token stays in its environment variable, and only
     * a signed-URL Star's snippet carries its signed URL.
     */
    public function prompt(Star $star, McpClient $client): string
    {
        $setup = $this->for($star, $client);
        $replace = ['star' => $star->name, 'client' => $client->label(), 'name' => self::serverName($star)];
        $steps = [];

        if ($star->access_mode === StarAccessMode::Token) {
            $steps[] = __('The Star takes a bearer token, which :client reads from the environment variable :variable. Never write a token into a config file. If :variable isn\'t set where :client runs, ask me to create a token for :client on the Star\'s Access page in Nexus (:url) and to put it in my shell profile (such as :profile):', [
                ...$replace,
                'variable' => self::tokenVariable($star),
                'url' => route('stars.access', $star),
                'profile' => self::SHELL_PROFILE,
            ])."\n\n".$this->fenced(self::tokenExport($star));
        }

        $steps[] = match (true) {
            $setup['file'] !== null => __('Add this to :file, keeping anything already in it:', ['file' => $setup['file']]),
            $setup['instruction'] !== null => $setup['instruction'],
            default => __('Run this in a terminal:'),
        }."\n\n".$this->fenced($setup['snippet']);

        if ($setup['login'] !== null) {
            $steps[] = $setup['login']['instruction'].($setup['login']['snippet'] !== null ? "\n\n".$this->fenced($setup['login']['snippet']) : '');
        }

        $steps[] = $star->access_mode === StarAccessMode::SignedUrl
            ? __('Restart :client if it is running, and call one of :name\'s tools to check it works. A signed URL doesn\'t say which client uses it, so Nexus notes only tool calls, not listing them.', $replace)
            : __('Restart :client if it is running, and check that :name lists its tools.', $replace);

        $intro = [
            __('Add the Nexus Star ":star" to :client as the remote MCP server :name.', $replace),
            __('Endpoint: :url', ['url' => $star->endpointUrl()]),
        ];

        if ($star->access_mode === StarAccessMode::SignedUrl) {
            $intro[] = __('Its URL in the setup is a signed URL that carries the Star\'s credential, so keep it out of shared or committed files.');
        }

        return implode("\n\n", [
            ...$intro,
            ...array_map(fn (int $number, string $step): string => ($number + 1).'. '.$step, array_keys($steps), $steps),
        ]);
    }

    /**
     * What to do once the client is set up, for the Star to hear from it.
     * A token or a connected app is noted on every request, so listing the
     * tools is enough; a signed URL names no one, so only a call counts.
     */
    public function checkHint(Star $star, McpClient $client): string
    {
        $replace = ['client' => $client->label(), 'star' => $star->name];

        return $star->access_mode === StarAccessMode::SignedUrl
            ? __('Restart :client and ask it to use one of :star\'s tools. This turns green as soon as :star hears from it.', $replace)
            : __('Restart :client and ask it to list its tools. This turns green as soon as :star hears from it.', $replace);
    }

    /**
     * Short tips for when the Star doesn't hear from the client.
     *
     * @return list<string>
     */
    public function troubleshooting(Star $star, McpClient $client): array
    {
        $replace = ['client' => $client->label(), 'star' => $star->name, 'name' => self::serverName($star)];

        $tips = match ($star->access_mode) {
            StarAccessMode::Token => [
                __(':client reads the token from :variable when it starts. After adding it to :profile, open a new terminal and restart :client from there.', [...$replace, 'variable' => self::tokenVariable($star), 'profile' => self::SHELL_PROFILE]),
                __('A revoked token is refused. Check the token is still listed on the Access page, or create another.'),
            ],
            StarAccessMode::SignedUrl => [
                __('A signed URL doesn\'t say which client uses it, so only tool calls are noted. Listing the tools isn\'t enough.'),
                __('Rotating the URL on the Access page stops the old one working. Set :client up again with the new one.', $replace),
            ],
            StarAccessMode::OAuth => [
                __(':client has to sign in to Nexus and you approve it for :star. Once you have, it is listed under connected apps on the Access page.', $replace),
                __('Approve it signed in as the account that owns :star. Another account can\'t.', $replace),
            ],
        };

        return [...$tips, __('Check that :client lists :name among its MCP servers without an error.', $replace)];
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
     * The line that puts a token in the environment variable: with the
     * token left out (`export NEXUS_WORK_TOKEN=nxs_…`), or with the one
     * just created, the only time Nexus has it.
     */
    public static function tokenExport(Star $star, #[SensitiveParameter] string $token = 'nxs_…'): string
    {
        return 'export '.self::tokenVariable($star).'='.$token;
    }

    /**
     * @return Setup
     */
    private function withToken(Star $star, McpClient $client): array
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

        return match ($client) {
            McpClient::ClaudeCode => $this->setup("claude mcp add-json --scope user {$name} '{$claudeCode}'"),
            McpClient::Codex => $this->setup($this->toml($name, $url, $variable), file: '~/.codex/config.toml'),
            McpClient::Cursor => $this->setup($cursor, file: '~/.cursor/mcp.json'),
            McpClient::Grok => $this->setup($this->toml($name, $url, $variable), file: '~/.grok/config.toml'),
            McpClient::ClaudeAi => throw new InvalidArgumentException('claude.ai takes only a URL, so it can\'t send a Star token.'),
        };
    }

    /**
     * Setup with nothing but the URL a client adds (`Star::clientUrl()`),
     * which carries whatever it needs to authenticate. claude.ai takes only
     * a URL, so it can be set up this way too.
     *
     * @return Setup
     */
    private function withUrl(Star $star, McpClient $client): array
    {
        $name = self::serverName($star);
        $url = $star->clientUrl();

        return match ($client) {
            McpClient::ClaudeAi => $this->setup($url, instruction: __('Open Settings → Connectors, choose "Add custom connector" and paste this URL:')),
            McpClient::ClaudeCode => $this->setup("claude mcp add --transport http --scope user {$name} '{$url}'"),
            McpClient::Codex => $this->setup($this->toml($name, $url), file: '~/.codex/config.toml'),
            McpClient::Cursor => $this->setup($this->json(['mcpServers' => [$name => ['url' => $url]]], JSON_PRETTY_PRINT), file: '~/.cursor/mcp.json'),
            McpClient::Grok => $this->setup($this->toml($name, $url), file: '~/.grok/config.toml'),
        };
    }

    /**
     * The URL-only setup, followed by the client's own way of signing in
     * to Nexus, where the user approves it for the Star.
     *
     * @return Setup
     */
    private function withOAuth(Star $star, McpClient $client): array
    {
        $name = self::serverName($star);
        $approve = __('Approve it in Nexus when your browser opens.');

        return [...$this->withUrl($star, $client), 'login' => match ($client) {
            McpClient::ClaudeAi => ['instruction' => __('Then choose Connect next to it. claude.ai sends you to Nexus to approve it.'), 'snippet' => null],
            McpClient::ClaudeCode => ['instruction' => __('Then sign in from a terminal, or run /mcp in Claude Code, choose :name and Authenticate.', ['name' => $name]).' '.$approve, 'snippet' => "claude mcp login {$name}"],
            McpClient::Codex => ['instruction' => __('Then sign in from a terminal.').' '.$approve, 'snippet' => "codex mcp login {$name}"],
            McpClient::Cursor => ['instruction' => __('Then sign in when Cursor\'s MCP settings say :name needs it, or with the Cursor CLI.', ['name' => $name]).' '.$approve, 'snippet' => "cursor-agent mcp login {$name}"],
            McpClient::Grok => ['instruction' => __('Then open /mcps in Grok, choose :name and press i to sign in.', ['name' => $name]).' '.$approve, 'snippet' => null],
        }];
    }

    /**
     * @return Setup
     */
    private function setup(string $snippet, ?string $file = null, ?string $instruction = null): array
    {
        return ['file' => $file, 'instruction' => $instruction, 'snippet' => $snippet, 'login' => null];
    }

    /**
     * The TOML table Codex and Grok read a server from, with the variable
     * its bearer token is read from when it takes one.
     */
    private function toml(string $name, string $url, ?string $variable = null): string
    {
        $toml = "[mcp_servers.{$name}]\nurl = \"{$url}\"";

        return $variable === null ? $toml : $toml."\nbearer_token_env_var = \"{$variable}\"";
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value, int $flags = 0): string
    {
        return json_encode($value, $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A snippet in a Markdown code fence.
     */
    private function fenced(string $snippet): string
    {
        return "```\n{$snippet}\n```";
    }
}
