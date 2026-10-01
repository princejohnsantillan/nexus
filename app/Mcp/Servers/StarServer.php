<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Downstream\RawJson;
use App\Mcp\Methods\CallStarTool;
use App\Mcp\Methods\ListStarTools;
use App\Mcp\RawResult;
use App\Mcp\StarCaller;
use App\Stars\StarInstructions;
use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Methods\Discover;
use Laravel\Mcp\Server\Methods\Initialize;
use Laravel\Mcp\Server\Methods\Ping;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use UnexpectedValueException;

/**
 * The MCP server behind `/mcp/{star}`. One class serves every Star: the
 * Star is the one the access middleware authenticated the request for.
 *
 * It speaks 2026-07-28 (`server/discover`) and 2025-11-25 (`initialize`),
 * offers the Star's tools and nothing else, and passes tool definitions
 * and results through as the exact JSON the downstream servers sent.
 */
class StarServer extends Server
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

    /**
     * Only what a Star serves; any other method is "method not found".
     *
     * @var array<string, class-string<Method>>
     */
    protected array $methods = [
        'server/discover' => Discover::class,
        'initialize' => Initialize::class,
        'ping' => Ping::class,
        'tools/list' => ListStarTools::class,
        'tools/call' => CallStarTool::class,
    ];

    protected function boot(): void
    {
        $star = resolve(StarCaller::class)->star;

        $this->name = "Nexus: {$star->name}";
        $this->instructions = resolve(StarInstructions::class)->for($star);
    }

    /**
     * Send a raw result with the members laravel/mcp adds to every result,
     * set in its JSON text: `resultType`, any cache hints, and Nexus's
     * server info in `_meta`, in place of the downstream server's own.
     */
    protected function send(JsonRpcResponse $response, ServerContext $context, ?JsonRpcRequest $request = null): void
    {
        if (! $response instanceof RawResult || ! $request instanceof JsonRpcRequest) {
            parent::send($response, $context, $request);

            return;
        }

        $result = $response->result;

        foreach (['resultType' => 'complete', ...$this->resolveCacheHints($request, $context)] as $key => $value) {
            $result = $this->set($result, $key, $this->encode($value));
        }

        $meta = RawJson::member($result, '_meta');
        $meta = $this->set($meta !== null && RawJson::isObject($meta) ? $meta : '{}', MetaKey::SERVER_INFO->value, $this->encode($context->implementation->toArray()));

        $this->transport->send(new RawResult($response->id, $this->set($result, '_meta', $meta))->toJson());
    }

    /**
     * The JSON object with the member set to the JSON value.
     *
     * @throws UnexpectedValueException when the JSON isn't an object
     */
    private function set(string $json, string $key, string $value): string
    {
        return RawJson::put($json, $key, $value) ?? throw new UnexpectedValueException('A raw result must be a JSON object.');
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
