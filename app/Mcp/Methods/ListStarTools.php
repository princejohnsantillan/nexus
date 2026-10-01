<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Actions\RefreshStaleCatalogs;
use App\Mcp\RawResult;
use App\Mcp\StarCaller;
use App\Stars\StarTool;
use App\Stars\StarToolset;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;

/**
 * `tools/list` for a Star: every tool that is on, in one page, each as the
 * exact JSON its server sent with the exposed name in place of its own.
 * Stale catalogs are refreshed in the background after the response.
 */
final readonly class ListStarTools implements Method
{
    public function __construct(
        private StarCaller $caller,
        private StarToolset $toolset,
        private RefreshStaleCatalogs $refreshStaleCatalogs,
    ) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): RawResult
    {
        $this->refreshStaleCatalogs->handle($this->caller->star);

        $tools = array_map(
            fn (StarTool $tool): string => $tool->definition(),
            $this->toolset->enabledTools($this->caller->star),
        );

        return new RawResult($request->id, '{"tools":['.implode(',', $tools).']}');
    }
}
