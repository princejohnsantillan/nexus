<?php

namespace App\Mcp\Methods;

use App\Mcp\Vaults\PromptProxy;
use App\Mcp\Vaults\VaultContext;
use App\Mcp\Vaults\VaultPrompts;
use Laravel\Mcp\Enums\ErrorCode;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use stdClass;

/**
 * prompts/get for a vault: forwards a switched-on prompt to its server.
 */
class GetVaultPrompt implements Method
{
    public function __construct(
        protected VaultContext $context,
        protected VaultPrompts $prompts,
        protected PromptProxy $proxy,
    ) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $name = $request->get('name');

        if (! is_string($name) || $name === '') {
            throw new JsonRpcException('Missing [name] parameter.', ErrorCode::INVALID_PARAMS->value, $request->id);
        }

        $prompt = $this->prompts->find($this->context->vault, $name)
            ?? throw new JsonRpcException("Prompt [{$name}] not found.", ErrorCode::INVALID_PARAMS->value, $request->id);

        return JsonRpcResponse::result($request->id, $this->proxy->get($this->context, $prompt, $this->rawArguments(), $request->id));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rawArguments(): array
    {
        $body = json_decode((string) request()->getContent(), false);

        $arguments = $body instanceof stdClass ? ($body->params->arguments ?? null) : null;

        return $arguments instanceof stdClass ? (array) $arguments : [];
    }
}
