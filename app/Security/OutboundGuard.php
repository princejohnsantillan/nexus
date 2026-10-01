<?php

namespace App\Security;

use Closure;

/**
 * Stops connections from reaching private networks (SSRF).
 *
 * A URL passes only if it uses an allowed scheme and every address its host
 * resolves to is publicly routable. The caller pins the first address for the
 * actual request, so the host cannot re-resolve to an internal IP afterwards.
 */
class OutboundGuard
{
    /**
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public function __construct(
        protected bool $blockPrivateNetworks = true,
        protected bool $requireHttps = true,
        protected ?Closure $resolver = null,
    ) {}

    /**
     * @return array{host: string, port: int, ip: string|null}
     *
     * @throws OutboundRequestBlocked
     */
    public function check(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new OutboundRequestBlocked("[{$url}] is not a valid URL.");
        }

        $scheme = strtolower($parts['scheme']);
        $allowedSchemes = $this->requireHttps ? ['https'] : ['https', 'http'];

        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new OutboundRequestBlocked('Only HTTPS URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new OutboundRequestBlocked('URLs must not contain credentials.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! $this->blockPrivateNetworks) {
            return ['host' => $host, 'port' => $port, 'ip' => null];
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);

        if ($addresses === []) {
            throw new OutboundRequestBlocked("Could not resolve [{$host}].");
        }

        foreach ($addresses as $address) {
            if (! static::isPublic($address)) {
                throw new OutboundRequestBlocked("[{$host}] resolves to a private or reserved address.");
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    public static function isPublic(string $ip): bool
    {
        // ::ffff:10.0.0.1 is an IPv4 address in disguise.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $matches) === 1) {
            $ip = $matches[1];
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE,
        ) !== false;
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        if ($this->resolver instanceof Closure) {
            return ($this->resolver)($host);
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $addresses = array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));

        if ($addresses === []) {
            $addresses = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
