<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * How MCP clients prove they may use a vault.
 */
enum VaultAuthMode: string implements HasDescription, HasLabel
{
    case SignedUrl = 'signed_url';
    case Token = 'token';
    case OAuth = 'oauth';

    public function getLabel(): string
    {
        return match ($this) {
            self::SignedUrl => 'Signed URL',
            self::Token => 'Bearer token in a header',
            self::OAuth => 'OAuth with your Nexus account',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::SignedUrl => 'The credential is part of the URL, for clients that can\'t send headers or sign in. Anyone with the URL can use the vault; rotate it to revoke.',
            self::Token => 'Clients send an nxs_ token you create here, one per client or machine, each revocable on its own.',
            self::OAuth => 'Clients open a browser where you sign in to Nexus and approve them. Works with claude.ai, Claude Code, Codex, Cursor and Grok; revoke any app here.',
        };
    }
}
