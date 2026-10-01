<?php

namespace Tests\Unit;

use App\Security\OutboundGuard;
use App\Security\OutboundRequestBlocked;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OutboundGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function privateAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private 10/8' => ['10.1.2.3'],
            'private 172.16/12' => ['172.20.0.5'],
            'private 192.168/16' => ['192.168.1.10'],
            'cloud metadata' => ['169.254.169.254'],
            'carrier-grade NAT' => ['100.64.0.1'],
            'unspecified' => ['0.0.0.0'],
            'IPv6 loopback' => ['::1'],
            'IPv6 link-local' => ['fe80::1'],
            'IPv6 unique local' => ['fd00::1'],
            'IPv4-mapped loopback' => ['::ffff:127.0.0.1'],
        ];
    }

    #[DataProvider('privateAddresses')]
    public function test_hosts_resolving_to_private_addresses_are_blocked(string $address): void
    {
        $guard = new OutboundGuard(resolver: fn (): array => [$address]);

        $this->expectException(OutboundRequestBlocked::class);

        $guard->check('https://innocent-looking.example.com/mcp');
    }

    public function test_one_private_address_among_public_ones_is_enough_to_block(): void
    {
        $guard = new OutboundGuard(resolver: fn (): array => ['93.184.216.34', '10.0.0.1']);

        $this->expectException(OutboundRequestBlocked::class);

        $guard->check('https://mixed.example.com/mcp');
    }

    public function test_public_hosts_pass_and_return_the_address_to_pin(): void
    {
        $guard = new OutboundGuard(resolver: fn (): array => ['93.184.216.34']);

        $this->assertSame(
            ['host' => 'mcp.example.com', 'port' => 443, 'ip' => '93.184.216.34'],
            $guard->check('https://mcp.example.com/mcp'),
        );
    }

    public function test_private_ip_literals_are_blocked_without_resolving(): void
    {
        $guard = new OutboundGuard(resolver: fn (): array => $this->fail('IP literals must not be resolved.'));

        $this->expectException(OutboundRequestBlocked::class);

        $guard->check('https://192.168.0.1/mcp');
    }

    public function test_plain_http_is_refused_unless_allowed(): void
    {
        $guard = new OutboundGuard(resolver: fn (): array => ['93.184.216.34']);

        $this->expectException(OutboundRequestBlocked::class);

        $guard->check('http://mcp.example.com/mcp');
    }

    public function test_urls_with_credentials_are_refused(): void
    {
        $guard = new OutboundGuard(resolver: fn (): array => ['93.184.216.34']);

        $this->expectException(OutboundRequestBlocked::class);

        $guard->check('https://user:pass@mcp.example.com/mcp');
    }

    public function test_unresolvable_hosts_are_refused(): void
    {
        $guard = new OutboundGuard(resolver: fn (): array => []);

        $this->expectException(OutboundRequestBlocked::class);

        $guard->check('https://does-not-exist.example.com/mcp');
    }

    public function test_the_guard_can_be_relaxed_for_local_development(): void
    {
        $guard = new OutboundGuard(blockPrivateNetworks: false, requireHttps: false);

        $this->assertSame(
            ['host' => 'localhost', 'port' => 8080, 'ip' => null],
            $guard->check('http://localhost:8080/mcp'),
        );
    }
}
