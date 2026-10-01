<?php

declare(strict_types=1);

namespace App\Downstream;

use App\Enums\ConnectionAuthType;
use App\Models\Connection;
use Laravel\Mcp\Schema\Implementation;

/**
 * Opens sessions to Connections' MCP servers, signed in the way each
 * Connection says, with the timeouts from `nexus.downstream`:
 *
 *     $tools = $downstream->session($connection)->listTools();
 *
 * Opening a session sends nothing; it connects on its first request.
 */
final readonly class DownstreamClient
{
    public function session(Connection $connection): DownstreamSession
    {
        $transport = new DownstreamTransport(
            $connection->url,
            connectTimeout: config()->float('nexus.downstream.connect_timeout'),
            callTimeout: config()->float('nexus.downstream.call_timeout'),
        );

        if ($connection->auth_type === ConnectionAuthType::Header) {
            $transport->withHeaders([$connection->headerName() => $connection->headerValue()]);
        }

        $client = new DownstreamMcpClient($transport, new Implementation(
            name: 'nexus',
            version: '1.0.0',
            title: config()->string('app.name'),
        ));

        return new DownstreamSession($client, $transport);
    }
}
