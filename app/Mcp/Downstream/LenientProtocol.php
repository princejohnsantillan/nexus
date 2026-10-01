<?php

namespace App\Mcp\Downstream;

use Laravel\Mcp\Client\Methods\Initialize;
use Laravel\Mcp\Client\NegotiatedConnection;
use Laravel\Mcp\Client\Protocol;
use Laravel\Mcp\Client\Schema\InitializeResult;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Schema\Implementation;

/**
 * laravel/mcp's client protocol, also accepting servers that answer the
 * handshake with 2025-03-26.
 *
 * The stock client rejects anything older than 2025-06-18. Many servers in
 * the wild still answer with 2025-03-26, and for tools/list and tools/call
 * the two versions are wire-compatible.
 */
class LenientProtocol extends Protocol
{
    protected function initialize(ProtocolVersion $protocolVersion, bool $pinned = false): NegotiatedConnection
    {
        $payload = $this->attempt(new Initialize($this->clientInfo, $protocolVersion), $protocolVersion);

        $result = ($payload['protocolVersion'] ?? null) === ProtocolVersion::V2025_03_26->value
            ? new InitializeResult(
                protocolVersion: ProtocolVersion::V2025_03_26->value,
                capabilities: is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [],
                serverInfo: Implementation::from($payload['serverInfo'] ?? null) ?? new Implementation('unknown', '0.0.0'),
                instructions: is_string($payload['instructions'] ?? null) ? $payload['instructions'] : null,
            )
            : InitializeResult::from($payload);

        $settled = ProtocolVersion::from($result->protocolVersion);

        if ($pinned && $settled !== $protocolVersion) {
            throw $this->versionMismatch($settled, $protocolVersion);
        }

        $this->notify('notifications/initialized', $settled);

        return new NegotiatedConnection($settled, $result);
    }
}
