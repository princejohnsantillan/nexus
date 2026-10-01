<?php

namespace App\Mcp\Methods;

use App\Mcp\Vaults\ExposedTool;
use App\Mcp\Vaults\VaultContext;
use App\Mcp\Vaults\VaultToolset;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * tools/list for a vault: every switched-on tool, in one page.
 */
class ListVaultTools implements Method
{
    public function __construct(
        protected VaultContext $context,
        protected VaultToolset $toolset,
    ) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, [
            'tools' => $this->toolset->enabled($this->context->vault)
                ->map(fn (ExposedTool $tool): array => $tool->toListEntry())
                ->all(),
        ]);
    }
}
