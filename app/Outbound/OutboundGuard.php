<?php

declare(strict_types=1);

namespace App\Outbound;

use App\Exceptions\OutboundRequestBlocked;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Lets requests leave Nexus only for public HTTPS servers.
 *
 * Users can point Nexus at any URL, so a URL passes only if it uses HTTPS and
 * every address its host resolves to is publicly routable. The addresses it
 * approved are returned so the request can be pinned to them, which stops the
 * host from re-resolving to an internal address before the connection.
 */
final readonly class OutboundGuard
{
    /**
     * IPv4 ranges that are not publicly routable.
     *
     * @var list<string>
     */
    private const array BLOCKED_IPV4_RANGES = [
        '0.0.0.0/8',        // "this network", including the unspecified address
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // carrier-grade NAT
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local, including cloud metadata endpoints
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // documentation (TEST-NET-1)
        '192.88.99.0/24',   // 6to4 relay anycast
        '192.168.0.0/16',   // private
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // documentation (TEST-NET-2)
        '203.0.113.0/24',   // documentation (TEST-NET-3)
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved, including broadcast
    ];

    /**
     * Global unicast IPv6. Everything outside it is blocked: unspecified,
     * loopback, IPv4-mapped, NAT64, unique local, link-local and multicast.
     */
    private const string GLOBAL_UNICAST_IPV6 = '2000::/3';

    /**
     * Special-purpose ranges inside global unicast IPv6.
     *
     * @var list<string>
     */
    private const array BLOCKED_IPV6_RANGES = [
        '2001::/23',        // IETF protocol assignments, including Teredo
        '2001:db8::/32',    // documentation
        '2002::/16',        // 6to4, which embeds an IPv4 address
        '3fff::/20',        // documentation
    ];

    public function __construct(
        private DnsResolver $resolver,
        private bool $blockPrivateNetworks = true,
        private bool $requireHttps = true,
    ) {}

    /**
     * Check that a request to the URL may leave Nexus, and return where it may connect.
     *
     * @throws OutboundRequestBlocked
     */
    public function check(string $url): OutboundTarget
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new OutboundRequestBlocked('That is not a valid URL.');
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && ($this->requireHttps || $scheme !== 'http')) {
            throw new OutboundRequestBlocked('Only HTTPS URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new OutboundRequestBlocked('URLs must not contain a username or password.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! $this->blockPrivateNetworks) {
            return new OutboundTarget($host, $port);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! self::isPublicAddress($host)) {
                throw new OutboundRequestBlocked("[{$host}] is a private or reserved address.");
            }

            return new OutboundTarget($host, $port);
        }

        if (! $this->isHostName($host)) {
            throw new OutboundRequestBlocked("[{$host}] is not a valid host name.");
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new OutboundRequestBlocked("Could not resolve [{$host}].");
        }

        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                throw new OutboundRequestBlocked("[{$host}] resolves to a private or reserved address.");
            }
        }

        return new OutboundTarget($host, $port, $addresses);
    }

    /**
     * Whether an IP address is publicly routable.
     */
    public static function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return ! IpUtils::checkIp($address, self::BLOCKED_IPV4_RANGES);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return IpUtils::checkIp($address, self::GLOBAL_UNICAST_IPV6)
                && ! IpUtils::checkIp($address, self::BLOCKED_IPV6_RANGES);
        }

        return false;
    }

    /**
     * Whether a host is a plain DNS name.
     *
     * Like browsers and curl, a numeric last label (127.1, 2130706433,
     * 0x7f.1) makes the host an IPv4 address in disguise, which the system
     * resolver would turn into an address without asking DNS. A trailing dot
     * is refused because curl may not match it against the pinned host.
     */
    private function isHostName(string $host): bool
    {
        if (str_ends_with($host, '.')) {
            return false;
        }

        $labels = explode('.', $host);

        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', end($labels)) === 1) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
