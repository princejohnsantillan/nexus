<?php

namespace App\Mcp\Servers;

use App\Mcp\Methods\CallVaultTool;
use App\Mcp\Methods\GetVaultPrompt;
use App\Mcp\Methods\ListVaultPrompts;
use App\Mcp\Methods\ListVaultTools;
use App\Mcp\Vaults\VaultContext;
use App\Mcp\Vaults\VaultInstructions;
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
        self::CAPABILITY_PROMPTS => [
            'listChanged' => false,
        ],
    ];

    protected function boot(): void
    {
        $this->addMethod('tools/list', ListVaultTools::class);
        $this->addMethod('tools/call', CallVaultTool::class);
        $this->addMethod('prompts/list', ListVaultPrompts::class);
        $this->addMethod('prompts/get', GetVaultPrompt::class);

        $vault = app(VaultContext::class)->vault;

        $this->name = "Nexus: {$vault->name}";
        $this->instructions = app(VaultInstructions::class)->for($vault);
    }
}
