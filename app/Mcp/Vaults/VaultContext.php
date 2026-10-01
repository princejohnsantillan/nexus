<?php

namespace App\Mcp\Vaults;

use App\Models\Vault;
use App\Models\VaultToken;

/**
 * The vault behind the current MCP request, and the credential that opened it.
 */
final class VaultContext
{
    public function __construct(
        public readonly Vault $vault,
        public readonly string $via,
        public readonly string $rateLimitKey,
        public readonly ?VaultToken $token = null,
    ) {}
}
