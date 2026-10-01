<?php

namespace App\Mcp\Servers;

use App\Mcp\Methods\CallVaultTool;
use App\Mcp\Methods\ListVaultTools;
use App\Mcp\Vaults\VaultContext;
use Laravel\Mcp\Server;

/**
 * The MCP server behind /mcp/{vault}. One class serves every vault; the
 * vault comes from the token the request was authenticated with.
 */
class VaultServer extends Server
{
    protected string $name = 'Nexus';

    protected string $version = '1.0.0';

    /**
     * @var array<string, array<string, bool>>
     */
    protected array $capabilities = [
        self::CAPABILITY_TOOLS => [
            'listChanged' => false,
        ],
    ];

    protected function boot(): void
    {
        $this->addMethod('tools/list', ListVaultTools::class);
        $this->addMethod('tools/call', CallVaultTool::class);

        $vault = app(VaultContext::class)->vault;

        $this->name = "Nexus: {$vault->name}";
        $this->instructions = trim(
            "Tools from the \"{$vault->name}\" Nexus vault. Each tool name starts with the connection it belongs to, "
            .'for example slack__search_messages. '.($vault->description ?? '')
        );
    }
}
