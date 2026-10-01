<?php

declare(strict_types=1);

namespace App\Outbound;

/**
 * Looks up the addresses a host name resolves to, for the outbound guard.
 */
interface DnsResolver
{
    /**
     * Resolve a host name to its IPv4 and IPv6 addresses.
     *
     * @return list<string> Empty when the host does not resolve.
     */
    public function resolve(string $host): array;
}
