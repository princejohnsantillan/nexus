<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Mcp\RawResult;
use App\Mcp\StarCaller;
use App\Stars\StarPrompt;
use App\Stars\StarPrompts;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;

/**
 * `prompts/list` for a Star: every prompt that is on, in one page, each as
 * the exact JSON its server sent with the exposed name in place of its own.
 */
final readonly class ListStarPrompts implements Method
{
    public function __construct(
        private StarCaller $caller,
        private StarPrompts $prompts,
    ) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): RawResult
    {
        $prompts = array_map(
            fn (StarPrompt $prompt): string => $prompt->definition(),
            $this->prompts->enabledPrompts($this->caller->star),
        );

        return new RawResult($request->id, '{"prompts":['.implode(',', $prompts).']}');
    }
}
