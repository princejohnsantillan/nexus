<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Outbound\DnsResolver;

/**
 * DNS for tests: listed hosts resolve to their records, every other host to a public address.
 */
final readonly class FakeDnsResolver implements DnsResolver
{
    /**
     * A public address (example.com's former one). Faked requests never connect to it.
     */
    public const string PUBLIC_ADDRESS = '93.184.215.14';

    /**
     * @param  array<string, list<string>>  $records  Addresses per host; an empty list makes the host unresolvable.
     */
    public function __construct(private array $records = []) {}

    public function resolve(string $host): array
    {
        return $this->records[$host] ?? [self::PUBLIC_ADDRESS];
    }
}
