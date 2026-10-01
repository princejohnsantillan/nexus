<?php

namespace App\Mcp\Downstream;

use Illuminate\Support\Arr;
use Laravel\Mcp\Client\Exceptions\OAuthException;
use Laravel\Mcp\Client\OAuth\AuthServerDiscovery;

/**
 * laravel/mcp's OAuth discovery, tolerating two deviations real servers make:
 *
 * - Slack's protected resource metadata names the resource
 *   `https://mcp.slack.com` while the server lives at `/mcp`. RFC 9728 says
 *   they must match exactly, so the stock client refuses. A resource on the
 *   same origin whose path is a prefix of the server's is accepted, and is
 *   remembered so sign-in can name the resource the way the server does.
 * - Google lists its authorization server as `https://accounts.google.com/`
 *   while its metadata's issuer has no trailing slash.
 *
 * Anything on a different origin is still rejected.
 */
class NexusAuthServerDiscovery extends AuthServerDiscovery
{
    /** The resource as the server's metadata names it, when that differs from the server URL. */
    public ?string $advertisedResource = null;

    /**
     * @param  array<string, mixed>  $resourceMetadata
     */
    protected function requireResourceMatches(array $resourceMetadata, string $resourceUrl): void
    {
        $resource = Arr::get($resourceMetadata, 'resource');

        if (! is_string($resource) || hash_equals($resourceUrl, $resource)) {
            return;
        }

        if (! static::isPrefixOnSameOrigin($resource, $resourceUrl)) {
            throw new OAuthException("Protected resource metadata resource [{$resource}] did not match the expected resource [{$resourceUrl}].");
        }

        $this->advertisedResource = $resource;
    }

    /**
     * @param  array<string, mixed>  $resourceMetadata
     */
    protected function issuerFrom(array $resourceMetadata): ?string
    {
        $issuer = parent::issuerFrom($resourceMetadata);

        return $issuer === null ? null : rtrim($issuer, '/');
    }

    public static function isPrefixOnSameOrigin(string $resource, string $url): bool
    {
        $resourceParts = parse_url($resource);
        $urlParts = parse_url($url);

        if (! is_array($resourceParts) || ! is_array($urlParts)) {
            return false;
        }

        foreach (['scheme', 'host', 'port'] as $part) {
            if (strtolower((string) ($resourceParts[$part] ?? '')) !== strtolower((string) ($urlParts[$part] ?? ''))) {
                return false;
            }
        }

        $prefix = rtrim($resourceParts['path'] ?? '', '/');
        $path = $urlParts['path'] ?? '';

        return $prefix === '' || $path === $prefix || str_starts_with($path, $prefix.'/');
    }
}
