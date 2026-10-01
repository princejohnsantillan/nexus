<?php

declare(strict_types=1);

use App\Exceptions\OutboundRequestBlocked;
use App\Outbound\OutboundGuard;
use App\Outbound\OutboundTarget;
use Tests\Support\FakeDnsResolver;

it('classifies addresses that are not publicly routable as blocked', function (string $address): void {
    expect(OutboundGuard::isPublicAddress($address))->toBeFalse();
})->with([
    'unspecified IPv4' => '0.0.0.0',
    '"this network"' => '0.1.2.3',
    'private 10/8' => '10.1.2.3',
    'carrier-grade NAT' => '100.64.0.1',
    'loopback' => '127.0.0.1',
    'loopback, rest of 127/8' => '127.255.255.254',
    'link-local' => '169.254.1.1',
    'cloud metadata' => '169.254.169.254',
    'private 172.16/12' => '172.20.0.5',
    'IETF protocol assignments' => '192.0.0.8',
    'documentation 192.0.2/24' => '192.0.2.10',
    '6to4 relay anycast' => '192.88.99.1',
    'private 192.168/16' => '192.168.1.10',
    'benchmarking' => '198.18.0.1',
    'documentation 198.51.100/24' => '198.51.100.7',
    'documentation 203.0.113/24' => '203.0.113.9',
    'multicast' => '224.0.0.1',
    'multicast, top of range' => '239.255.255.250',
    'reserved' => '240.0.0.1',
    'broadcast' => '255.255.255.255',
    'unspecified IPv6' => '::',
    'IPv6 loopback' => '::1',
    'IPv4-mapped IPv6, private' => '::ffff:10.0.0.1',
    'IPv4-mapped IPv6, public' => '::ffff:8.8.8.8',
    'IPv4-compatible IPv6' => '::127.0.0.1',
    'NAT64 with a private IPv4' => '64:ff9b::a00:1',
    'discard-only' => '100::1',
    'Teredo' => '2001::1',
    'IPv6 documentation' => '2001:db8::1',
    '6to4' => '2002:a00:1::1',
    'IPv6 documentation 3fff::/20' => '3fff::1',
    'IPv6 unique local' => 'fd00::1',
    'IPv6 site-local (deprecated)' => 'fec0::1',
    'IPv6 link-local' => 'fe80::1',
    'IPv6 multicast' => 'ff02::1',
    'not an address' => 'example.com',
]);

it('classifies publicly routable addresses as public', function (string $address): void {
    expect(OutboundGuard::isPublicAddress($address))->toBeTrue();
})->with([
    'IPv4' => '93.184.215.14',
    'IPv4 next to private 172.16/12' => '172.32.0.1',
    'IPv4 next to carrier-grade NAT' => '100.128.0.1',
    'IPv6' => '2606:4700:4700::1111',
    'IPv6 next to Teredo' => '2001:4860:4860::8888',
]);

it('accepts a public HTTPS host and returns the addresses to pin it to', function (): void {
    $guard = new OutboundGuard(new FakeDnsResolver(['mcp.example.com' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']]));

    $target = $guard->check('https://MCP.example.com/mcp?x=1');

    expect($target)->toEqual(new OutboundTarget('mcp.example.com', 443, ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']))
        ->and($target->curlResolveEntry())->toBe('mcp.example.com:443:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]');
});

it('keeps an explicit port in the pinned target', function (): void {
    $guard = new OutboundGuard(new FakeDnsResolver);

    expect($guard->check('https://mcp.example.com:8443/mcp')->curlResolveEntry())
        ->toBe('mcp.example.com:8443:'.FakeDnsResolver::PUBLIC_ADDRESS);
});

it('accepts a public IP address without a DNS lookup to pin', function (string $url, string $host): void {
    $guard = new OutboundGuard(new FakeDnsResolver);

    $target = $guard->check($url);

    expect($target)->toEqual(new OutboundTarget($host, 443))
        ->and($target->curlResolveEntry())->toBeNull();
})->with([
    'IPv4' => ['https://93.184.215.14/mcp', '93.184.215.14'],
    'IPv6' => ['https://[2606:4700:4700::1111]/mcp', '2606:4700:4700::1111'],
]);

it('rejects a URL that is not HTTPS', function (string $url): void {
    $guard = new OutboundGuard(new FakeDnsResolver);

    expect(fn (): OutboundTarget => $guard->check($url))
        ->toThrow(OutboundRequestBlocked::class, 'Only HTTPS URLs are allowed.');
})->with([
    'http' => 'http://mcp.example.com/mcp',
    'ftp' => 'ftp://mcp.example.com/mcp',
    'gopher' => 'gopher://mcp.example.com:70/_',
]);

it('rejects a string that is not a URL with a host', function (string $url): void {
    $guard = new OutboundGuard(new FakeDnsResolver);

    expect(fn (): OutboundTarget => $guard->check($url))
        ->toThrow(OutboundRequestBlocked::class, 'That is not a valid URL.');
})->with([
    'no scheme' => 'mcp.example.com/mcp',
    'file' => 'file:///etc/passwd',
    'unparseable' => 'https://:80',
]);

it('rejects a URL with a username or password', function (): void {
    $guard = new OutboundGuard(new FakeDnsResolver);

    expect(fn (): OutboundTarget => $guard->check('https://user:secret@mcp.example.com/mcp'))
        ->toThrow(OutboundRequestBlocked::class, 'URLs must not contain a username or password.');
});

it('rejects a host that resolves to a private address', function (): void {
    $guard = new OutboundGuard(new FakeDnsResolver(['rebind.example.com' => ['169.254.169.254']]));

    expect(fn (): OutboundTarget => $guard->check('https://rebind.example.com/latest/meta-data'))
        ->toThrow(OutboundRequestBlocked::class, '[rebind.example.com] resolves to a private or reserved address.');
});

it('rejects a host when any one of its addresses is private', function (): void {
    $guard = new OutboundGuard(new FakeDnsResolver(['mixed.example.com' => ['93.184.215.14', '2606:4700:4700::1111', '::1']]));

    expect(fn (): OutboundTarget => $guard->check('https://mixed.example.com/mcp'))
        ->toThrow(OutboundRequestBlocked::class, '[mixed.example.com] resolves to a private or reserved address.');
});

it('rejects a host that does not resolve', function (): void {
    $guard = new OutboundGuard(new FakeDnsResolver(['nowhere.example.com' => []]));

    expect(fn (): OutboundTarget => $guard->check('https://nowhere.example.com/mcp'))
        ->toThrow(OutboundRequestBlocked::class, 'Could not resolve [nowhere.example.com].');
});

it('rejects a private IP address without asking DNS', function (string $url, string $host): void {
    $guard = new OutboundGuard(new FakeDnsResolver([$host => [FakeDnsResolver::PUBLIC_ADDRESS]]));

    expect(fn (): OutboundTarget => $guard->check($url))
        ->toThrow(OutboundRequestBlocked::class, "[{$host}] is a private or reserved address.");
})->with([
    'IPv4' => ['https://192.168.0.1/mcp', '192.168.0.1'],
    'IPv6' => ['https://[::1]/mcp', '::1'],
    'IPv4-mapped IPv6' => ['https://[::ffff:127.0.0.1]/mcp', '::ffff:127.0.0.1'],
]);

it('rejects a host that is an IPv4 address in disguise', function (string $host): void {
    $guard = new OutboundGuard(new FakeDnsResolver);

    expect(fn (): OutboundTarget => $guard->check("https://{$host}/mcp"))
        ->toThrow(OutboundRequestBlocked::class, "[{$host}] is not a valid host name.");
})->with([
    'decimal' => '2130706433',
    'shortened' => '127.1',
    'hexadecimal' => '0x7f.1',
    'leading zeros' => '127.000.000.001',
]);

it('rejects a host name curl might read differently', function (string $host): void {
    $guard = new OutboundGuard(new FakeDnsResolver);

    expect(fn (): OutboundTarget => $guard->check("https://{$host}/mcp"))
        ->toThrow(OutboundRequestBlocked::class, 'is not a valid host name.');
})->with([
    'trailing dot' => 'mcp.example.com.',
    'underscore' => 'mcp_server.example.com',
    'IPv6 zone' => '[fe80::1%25en0]',
]);

it('allows plain HTTP when HTTPS is not required', function (): void {
    $guard = new OutboundGuard(new FakeDnsResolver, requireHttps: false);

    expect($guard->check('http://mcp.example.com/mcp'))
        ->toEqual(new OutboundTarget('mcp.example.com', 80, [FakeDnsResolver::PUBLIC_ADDRESS]));
});

it('allows private hosts without resolving them when private networks are not blocked', function (): void {
    $guard = new OutboundGuard(new FakeDnsResolver(['localhost' => []]), blockPrivateNetworks: false);

    expect($guard->check('https://localhost:8080/mcp'))
        ->toEqual(new OutboundTarget('localhost', 8080));
});
