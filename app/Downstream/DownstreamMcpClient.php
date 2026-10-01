<?php

declare(strict_types=1);

namespace App\Downstream;

use App\Concerns\KeepsSecretsInMemory;
use Laravel\Mcp\Client;
use Laravel\Mcp\Schema\Implementation;

/**
 * laravel/mcp's client, speaking the lenient protocol over Nexus's transport,
 * and able to send any request the session builds.
 *
 * Its transport holds the Connection's credentials, so it can't be serialized.
 */
final class DownstreamMcpClient extends Client
{
    use KeepsSecretsInMemory;

    public function __construct(DownstreamTransport $transport, Implementation $clientInfo)
    {
        parent::__construct($transport, $clientInfo);

        $this->protocol = new LenientProtocol($transport, $clientInfo);
    }

    /**
     * Send a request, connecting first if needed. Its result is in the transport.
     */
    public function send(RawRequest $request): void
    {
        $request->handle($this->protocol);
    }
}
