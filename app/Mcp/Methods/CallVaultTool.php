<?php

namespace App\Mcp\Methods;

use App\Mcp\Vaults\ToolProxy;
use App\Mcp\Vaults\VaultContext;
use App\Mcp\Vaults\VaultToolset;
use Laravel\Mcp\Enums\ErrorCode;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use stdClass;

/**
 * tools/call for a vault: forwards a switched-on tool to its server.
 */
class CallVaultTool implements Method
{
    public function __construct(
        protected VaultContext $context,
        protected VaultToolset $toolset,
        protected ToolProxy $proxy,
    ) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $name = $request->get('name');

        if (! is_string($name) || $name === '') {
            throw new JsonRpcException('Missing [name] parameter.', ErrorCode::INVALID_PARAMS->value, $request->id);
        }

        $tool = $this->toolset->find($this->context->vault, $name)
            ?? throw new JsonRpcException("Tool [{$name}] not found.", ErrorCode::INVALID_PARAMS->value, $request->id);

        return JsonRpcResponse::result($request->id, $this->proxy->call($this->context, $tool, $this->rawArguments()));
    }

    /**
     * The arguments as the client sent them. The parsed request has already
     * turned `{}` into `[]`, so read them again from the raw body.
     *
     * @return array<string, mixed>
     */
    protected function rawArguments(): array
    {
        $body = json_decode((string) request()->getContent(), false);

        $arguments = $body instanceof stdClass ? ($body->params->arguments ?? null) : null;

        return $arguments instanceof stdClass ? (array) $arguments : [];
    }
}
