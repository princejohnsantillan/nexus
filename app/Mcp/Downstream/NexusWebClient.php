<?php

namespace App\Mcp\Downstream;

use Laravel\Mcp\Client\Transport\HttpTransport;
use Laravel\Mcp\Schema\Implementation;
use Laravel\Mcp\WebClient;

class NexusWebClient extends WebClient
{
    public function __construct(HttpTransport $httpTransport, ?Implementation $clientInfo = null)
    {
        parent::__construct($httpTransport, $clientInfo);

        $this->protocol = new LenientProtocol($this->transport, $this->clientInfo);
    }
}
