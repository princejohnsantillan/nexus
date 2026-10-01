<?php

declare(strict_types=1);

namespace App\Outbound;

/**
 * Resolves host names through the system's DNS resolver.
 */
final class SystemDnsResolver implements DnsResolver
{
    /**
     * Look up A and AAAA records, falling back to the system's host lookup
     * (IPv4 only) when DNS returns nothing. Lookup failures count as "does
     * not resolve"; they raise warnings, so they are silenced.
     */
    public function resolve(string $host): array
    {
        $addresses = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            $addresses = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
