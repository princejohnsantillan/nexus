<?php

declare(strict_types=1);

namespace App\Downstream;

use App\Exceptions\DownstreamRequestFailed;
use Laravel\Mcp\Client\Methods\Initialize;
use Laravel\Mcp\Client\NegotiatedConnection;
use Laravel\Mcp\Client\Protocol;
use Laravel\Mcp\Client\Schema\InitializeResult;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Schema\Implementation;

/**
 * laravel/mcp's client protocol, also accepting servers that answer the
 * `initialize` handshake with 2025-03-26.
 *
 * The protocol tries the stateless 2026-07-28 `server/discover` first and
 * falls back to `initialize`, offering 2025-11-25. A server answers with the
 * version it settles on. The stock client accepts 2025-11-25 and 2025-06-18
 * only, but many servers still answer 2025-03-26, which is the same on the
 * wire for listing and calling tools. A server's info and capabilities are
 * read leniently too: Nexus needs neither to list or call tools.
 */
final class LenientProtocol extends Protocol
{
    /**
     * The versions Nexus accepts in an `initialize` answer.
     *
     * @var list<ProtocolVersion>
     */
    private const array INITIALIZE_VERSIONS = [
        ProtocolVersion::V2025_11_25,
        ProtocolVersion::V2025_06_18,
        ProtocolVersion::V2025_03_26,
    ];

    /**
     * @throws DownstreamRequestFailed when the server settles on a version Nexus doesn't speak
     */
    protected function initialize(ProtocolVersion $protocolVersion, bool $pinned = false): NegotiatedConnection
    {
        $payload = $this->attempt(
            new RawRequest('initialize', new Initialize($this->clientInfo, $protocolVersion)->params()),
            $protocolVersion,
        );

        $settled = is_string($payload['protocolVersion'] ?? null) ? ProtocolVersion::tryFrom($payload['protocolVersion']) : null;

        if (! in_array($settled, self::INITIALIZE_VERSIONS, true)) {
            throw DownstreamRequestFailed::unsupportedProtocolVersion();
        }

        if ($pinned && $settled !== $protocolVersion) {
            throw $this->versionMismatch($settled, $protocolVersion);
        }

        $capabilities = $payload['capabilities'] ?? null;
        $instructions = $payload['instructions'] ?? null;

        $result = new InitializeResult(
            protocolVersion: $settled->value,
            capabilities: is_array($capabilities) ? array_filter($capabilities, is_string(...), ARRAY_FILTER_USE_KEY) : [],
            serverInfo: Implementation::from($payload['serverInfo'] ?? null) ?? new Implementation('unknown', '0.0.0'),
            instructions: is_string($instructions) ? $instructions : null,
        );

        $this->notify('notifications/initialized', $settled);

        return new NegotiatedConnection($settled, $result);
    }
}
