<?php

declare(strict_types=1);

namespace App\Mcp;

use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * A JSON-RPC result sent as the exact JSON object given, rather than built
 * from PHP values, which would turn `{}` into `[]` and round long numbers.
 * The Star's server adds the protocol members every result carries.
 */
final class RawResult extends JsonRpcResponse
{
    /**
     * @param  string  $result  The result, a JSON object.
     */
    public function __construct(
        public readonly int|string $id,
        public readonly string $result,
    ) {
        parent::__construct(['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [...parent::toArray(), 'result' => json_decode($this->result, true)];
    }

    public function toJson(int $options = 0): string
    {
        return '{"jsonrpc":"2.0","id":'.json_encode($this->id, JSON_THROW_ON_ERROR).',"result":'.$this->result.'}';
    }
}
