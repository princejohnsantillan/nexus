<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Actions\RecordActivity;
use App\Downstream\RawJson;
use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Mcp\RawResult;
use App\Mcp\StarCaller;
use App\Mcp\ToolProxy;
use App\Stars\StarTool;
use App\Stars\StarToolset;
use Illuminate\Http\Request;
use Laravel\Mcp\Enums\ErrorCode;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;

/**
 * `tools/call` for a Star: forwards a tool that is on to its Connection's
 * server, with the arguments exactly as the client sent them. A tool that
 * is off, or that none of the Star's Connections has, is refused with
 * "invalid params", as an unknown tool is; so is a call without a name or
 * with arguments that aren't an object. Every refusal is recorded as denied.
 */
final readonly class CallStarTool implements Method
{
    public function __construct(
        private StarCaller $caller,
        private StarToolset $toolset,
        private ToolProxy $proxy,
        private RecordActivity $recordActivity,
        private Request $httpRequest,
    ) {}

    /**
     * @throws JsonRpcException
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): RawResult
    {
        $startedAt = hrtime(true);
        $name = $request->get('name');

        if (! is_string($name) || $name === '') {
            $this->deny($startedAt, null);

            throw new JsonRpcException('Missing [name] parameter.', ErrorCode::INVALID_PARAMS->value, $request->id);
        }

        $tool = $this->toolset->tool($this->caller->star, $name);

        if (! $tool instanceof StarTool || ! $tool->enabled) {
            $this->deny($startedAt, $name, $tool);

            throw new JsonRpcException("Tool [{$name}] not found.", ErrorCode::INVALID_PARAMS->value, $request->id);
        }

        $arguments = $this->arguments();

        if ($arguments === null) {
            $this->deny($startedAt, $name, $tool);

            throw new JsonRpcException('Invalid params: The [arguments] member must be an object.', ErrorCode::INVALID_PARAMS->value, $request->id);
        }

        return new RawResult($request->id, $this->proxy->call($this->caller, $tool, $arguments, $startedAt));
    }

    /**
     * Record a call refused before it reached the server: by the name the
     * client sent, if any, and the tool's Connection when the Star has it.
     */
    private function deny(int $startedAt, ?string $name, ?StarTool $tool = null): void
    {
        $this->recordActivity->handle(
            caller: $this->caller,
            kind: ActivityKind::Tool,
            exposedName: $name,
            connection: $tool?->connection,
            downstreamName: $tool?->tool->name,
            status: ActivityStatus::Denied,
            startedAt: $startedAt,
        );
    }

    /**
     * The arguments as the exact JSON object the client sent, `{}` when it
     * sent none, or null when they aren't an object. The parsed request has
     * already turned `{}` into `[]`, so they are read from the raw body.
     */
    private function arguments(): ?string
    {
        $params = RawJson::member($this->httpRequest->getContent(), 'params');
        $arguments = $params === null ? null : RawJson::member($params, 'arguments');

        if ($arguments === null) {
            return '{}';
        }

        return RawJson::isObject($arguments) ? $arguments : null;
    }
}
