<?php

namespace App\Mcp\Vaults;

use App\Models\Vault;
use App\Models\VaultToken;

/**
 * The vault and token behind the current MCP request.
 */
final class VaultContext
{
    public function __construct(
        public readonly Vault $vault,
        public readonly VaultToken $token,
    ) {}
}
