<?php

declare(strict_types=1);

namespace App\Downstream;

use Laravel\Mcp\Client\Contracts\Method;
use Laravel\Mcp\Client\Protocol;

/**
 * A JSON-RPC request whose result the session reads raw from the transport,
 * rather than through one of laravel/mcp's method classes, which decode
 * results into arrays, reject a whole list over one entry they can't read,
 * and rebuild tool arguments as arrays. Its params are encoded as given, so
 * an argument object passed in stays an object, `{}` included.
 *
 * @implements Method<mixed>
 */
final readonly class RawRequest implements Method
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        private string $method,
        private array $params = [],
    ) {}

    public function method(): string
    {
        return $this->method;
    }

    /**
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(Protocol $protocol): array
    {
        return $protocol->dispatch($this);
    }
}
