<?php

namespace App\Mcp\Methods;

use App\Mcp\Vaults\ExposedPrompt;
use App\Mcp\Vaults\VaultContext;
use App\Mcp\Vaults\VaultPrompts;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * prompts/list for a vault: every switched-on prompt, in one page.
 */
class ListVaultPrompts implements Method
{
    public function __construct(
        protected VaultContext $context,
        protected VaultPrompts $prompts,
    ) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, [
            'prompts' => $this->prompts->enabled($this->context->vault)
                ->map(fn (ExposedPrompt $prompt): array => $prompt->toListEntry())
                ->all(),
        ]);
    }
}
