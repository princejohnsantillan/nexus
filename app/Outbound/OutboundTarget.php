<?php

declare(strict_types=1);

namespace App\Outbound;

/**
 * Where an approved request may connect.
 */
final readonly class OutboundTarget
{
    /**
     * @param  list<string>  $addresses  The addresses the host is pinned to. Empty when there is no DNS lookup to pin: the host is an IP address, or the guard was relaxed locally.
     */
    public function __construct(
        public string $host,
        public int $port,
        public array $addresses = [],
    ) {}

    /**
     * The curl resolve entry that pins the host to its approved addresses,
     * e.g. "mcp.example.com:443:93.184.215.14,[2606:2800::1]".
     */
    public function curlResolveEntry(): ?string
    {
        if ($this->addresses === []) {
            return null;
        }

        $addresses = array_map(
            fn (string $address): string => str_contains($address, ':') ? "[{$address}]" : $address,
            $this->addresses,
        );

        return "{$this->host}:{$this->port}:".implode(',', $addresses);
    }
}
