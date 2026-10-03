<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * An MCP client a Star's overview can set up: the harnesses Nexus writes
 * setup for. The cases are in the order the setup offers them.
 */
enum McpClient: string
{
    case ClaudeAi = 'claude-ai';
    case ClaudeCode = 'claude-code';
    case Codex = 'codex';
    case Cursor = 'cursor';
    case Grok = 'grok';

    public function label(): string
    {
        return match ($this) {
            self::ClaudeAi => 'claude.ai',
            self::ClaudeCode => 'Claude Code',
            self::Codex => 'Codex',
            self::Cursor => 'Cursor',
            self::Grok => 'Grok',
        };
    }

    /**
     * Whether the client can reach a Star in this access mode. claude.ai
     * takes nothing but a URL, so it can't send a token in a header.
     */
    public function supports(StarAccessMode $mode): bool
    {
        return $this !== self::ClaudeAi || $mode !== StarAccessMode::Token;
    }

    /**
     * The clients that can reach a Star in this access mode, in order.
     *
     * @return list<self>
     */
    public static function for(StarAccessMode $mode): array
    {
        return array_values(array_filter(self::cases(), fn (self $client): bool => $client->supports($mode)));
    }

    /**
     * The client to show first for a Star in this access mode: the one the
     * browser remembers choosing (its value), when it can reach the Star,
     * or else the first that can.
     */
    public static function preferredFor(StarAccessMode $mode, mixed $remembered): self
    {
        $client = is_string($remembered) ? self::tryFrom($remembered) : null;

        return $client?->supports($mode) === true ? $client : self::for($mode)[0];
    }

    /**
     * Whether a name a credential carries (a Star token's name, or the
     * name an OAuth client registered with) names this client. Case,
     * spaces and punctuation are ignored, and the name may say more:
     * "Claude Code (nexus-work)" names Claude Code, "cursor-laptop"
     * Cursor. claude.ai registers as "Claude", so plain "Claude" names
     * claude.ai, as does any name with "claude.ai" in it, and never Claude
     * Code.
     */
    public function isNamedIn(string $name): bool
    {
        $name = preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '';

        return match ($this) {
            self::ClaudeAi => $name === 'claude' || str_contains($name, 'claudeai'),
            self::ClaudeCode => str_contains($name, 'claudecode'),
            self::Codex => str_contains($name, 'codex'),
            self::Cursor => str_contains($name, 'cursor'),
            self::Grok => str_contains($name, 'grok'),
        };
    }
}
