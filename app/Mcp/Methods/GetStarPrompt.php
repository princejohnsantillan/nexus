<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Actions\RecordActivity;
use App\Downstream\RawJson;
use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Mcp\PromptProxy;
use App\Mcp\RawResult;
use App\Mcp\StarCaller;
use App\Stars\StarPrompt;
use App\Stars\StarPrompts;
use Illuminate\Http\Request;
use Laravel\Mcp\Enums\ErrorCode;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;

/**
 * `prompts/get` for a Star: forwards a prompt that is on to its
 * Connection's server, with the arguments exactly as the client sent them.
 * A prompt that is off, or that none of the Star's Connections has, is
 * refused with "invalid params", as an unknown prompt is; so is a request
 * without a name or with arguments that aren't an object. Every refusal is
 * recorded as denied.
 */
final readonly class GetStarPrompt implements Method
{
    public function __construct(
        private StarCaller $caller,
        private StarPrompts $prompts,
        private PromptProxy $proxy,
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

        $prompt = $this->prompts->prompt($this->caller->star, $name);

        if (! $prompt instanceof StarPrompt || ! $prompt->enabled) {
            $this->deny($startedAt, $name, $prompt);

            throw new JsonRpcException("Prompt [{$name}] not found.", ErrorCode::INVALID_PARAMS->value, $request->id);
        }

        $arguments = $this->arguments();

        if ($arguments === null) {
            $this->deny($startedAt, $name, $prompt);

            throw new JsonRpcException('Invalid params: The [arguments] member must be an object.', ErrorCode::INVALID_PARAMS->value, $request->id);
        }

        return new RawResult($request->id, $this->proxy->get($this->caller, $prompt, $arguments, $startedAt, $request->id));
    }

    /**
     * Record a request refused before it reached the server: by the name
     * the client sent, if any, and the prompt's Connection when the Star
     * has it.
     */
    private function deny(int $startedAt, ?string $name, ?StarPrompt $prompt = null): void
    {
        $this->recordActivity->handle(
            caller: $this->caller,
            kind: ActivityKind::Prompt,
            exposedName: $name,
            connection: $prompt?->connection,
            downstreamName: $prompt?->prompt->name,
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
