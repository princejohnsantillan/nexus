<?php

declare(strict_types=1);

namespace App\ConnectionOAuth;

use App\Exceptions\ConnectionSignInFailed;
use Illuminate\Support\Arr;
use Laravel\Mcp\Client\OAuth\WwwAuthenticateChallenge;

/**
 * Finds out how to sign in to an MCP server, the way the MCP authorization
 * specification describes:
 *
 * 1. The server's protected-resource metadata (RFC 9728), from the URL its
 *    401 challenge names, or else its well-known URL: under the server's
 *    path first, then at the root. A server without any is its own
 *    authorization server, as servers on the 2025-03-26 protocol are.
 * 2. Its authorization server's metadata (RFC 8414), or its OpenID
 *    configuration, at each well-known URL for the issuer in turn.
 *
 * Real servers bend the rules in two ways it tolerates: an issuer listed
 * with a trailing slash its metadata doesn't have (or the other way round),
 * and a resource named by a shorter URL on the same origin (e.g. the
 * origin itself), which sign-in then names the way the server does. A
 * resource on another origin, or an issuer whose metadata names another
 * issuer, is refused.
 */
final readonly class AuthorizationServerDiscovery
{
    public function __construct(private OAuthRequests $requests) {}

    /**
     * @param  string  $serverUrl  The Connection's MCP server.
     * @param  WwwAuthenticateChallenge  $challenge  How the server refused Nexus without credentials.
     *
     * @throws ConnectionSignInFailed
     */
    public function discover(string $serverUrl, WwwAuthenticateChallenge $challenge): Discovery
    {
        $resourceMetadata = $this->resourceMetadata($serverUrl, $challenge->resourceMetadataUrl);

        $resource = $resourceMetadata['resource'] ?? null;

        if ($resource !== null && (! is_string($resource) || ! self::isSameResource($resource, $serverUrl))) {
            throw ConnectionSignInFailed::because(__('The server\'s sign-in settings are for a different server, so Nexus won\'t use them.'));
        }

        $servers = $resourceMetadata['authorization_servers'] ?? null;
        $issuer = is_array($servers) && is_string($servers[0] ?? null) ? $servers[0] : self::origin($serverUrl);

        if (! $this->isHttpsUrl($issuer)) {
            throw ConnectionSignInFailed::because(__('The server\'s authorization server isn\'t on HTTPS, so Nexus won\'t sign in with it.'));
        }

        $metadata = $this->serverMetadata($issuer);

        $authorizationEndpoint = $metadata['authorization_endpoint'] ?? null;
        $tokenEndpoint = $metadata['token_endpoint'] ?? null;
        $registrationEndpoint = $metadata['registration_endpoint'] ?? null;

        if (! is_string($authorizationEndpoint) || ! $this->isHttpsUrl($authorizationEndpoint) || ! is_string($tokenEndpoint) || $tokenEndpoint === '') {
            throw ConnectionSignInFailed::because(__('The server\'s authorization server doesn\'t say where to sign in over HTTPS.'));
        }

        if (! in_array('S256', $this->strings($metadata, 'code_challenge_methods_supported'), true)) {
            throw ConnectionSignInFailed::because(__('The server\'s authorization server doesn\'t support PKCE with S256, which Nexus requires.'));
        }

        return new Discovery(
            resource: is_string($resource) ? $resource : $serverUrl,
            issuer: is_string($metadata['issuer'] ?? null) ? $metadata['issuer'] : $issuer,
            authorizationEndpoint: $authorizationEndpoint,
            tokenEndpoint: $tokenEndpoint,
            registrationEndpoint: is_string($registrationEndpoint) && $registrationEndpoint !== '' ? $registrationEndpoint : null,
            scopesSupported: $this->strings($resourceMetadata, 'scopes_supported'),
            tokenEndpointAuthMethods: $this->strings($metadata, 'token_endpoint_auth_methods_supported'),
            supportsMetadataDocuments: ($metadata['client_id_metadata_document_supported'] ?? false) === true,
            returnsIssuer: ($metadata['authorization_response_iss_parameter_supported'] ?? false) === true,
        );
    }

    /**
     * Whether two issuers are the same, ignoring a trailing slash.
     */
    public static function isSameIssuer(string $issuer, string $other): bool
    {
        return rtrim($issuer, '/') === rtrim($other, '/');
    }

    /**
     * Whether a protected resource's metadata may describe the server: it
     * names the server's URL, or a URL on the same origin whose path is a
     * prefix of the server's, segment by segment. Trailing slashes are
     * ignored.
     */
    public static function isSameResource(string $resource, string $serverUrl): bool
    {
        $resourceParts = parse_url($resource);
        $serverParts = parse_url($serverUrl);

        if (! is_array($resourceParts) || ! is_array($serverParts) || self::origin($resource) !== self::origin($serverUrl)) {
            return false;
        }

        $prefix = rtrim($resourceParts['path'] ?? '', '/');
        $path = rtrim($serverParts['path'] ?? '', '/');

        return $prefix === '' || $path === $prefix || str_starts_with($path, $prefix.'/');
    }

    /**
     * The protected-resource metadata: from the challenge's URL, which must
     * answer, or else the first well-known URL that does. Empty when the
     * server publishes none.
     *
     * @return array<string, mixed>
     */
    private function resourceMetadata(string $serverUrl, ?string $challengeUrl): array
    {
        if ($challengeUrl !== null && $challengeUrl !== '') {
            return $this->requests->getJson($challengeUrl)
                ?? throw ConnectionSignInFailed::because(__('Nexus couldn\'t read the server\'s sign-in settings at the address it gave.'));
        }

        $origin = self::origin($serverUrl);
        $path = (string) parse_url($serverUrl, PHP_URL_PATH);
        $urls = array_unique([
            $origin.'/.well-known/oauth-protected-resource'.($path === '/' ? '' : $path),
            $origin.'/.well-known/oauth-protected-resource',
        ]);

        foreach ($urls as $url) {
            $metadata = $this->requests->getJson($url);

            if ($metadata !== null) {
                return $metadata;
            }
        }

        return [];
    }

    /**
     * The authorization server's metadata, from the first well-known URL
     * that answers for the issuer, OAuth's before OpenID Connect's.
     *
     * @return array<string, mixed>
     */
    private function serverMetadata(string $issuer): array
    {
        $origin = self::origin($issuer);
        $path = rtrim((string) parse_url($issuer, PHP_URL_PATH), '/');

        $urls = $path === ''
            ? [$origin.'/.well-known/oauth-authorization-server', $origin.'/.well-known/openid-configuration']
            : [
                $origin.'/.well-known/oauth-authorization-server'.$path,
                $origin.'/.well-known/openid-configuration'.$path,
                $origin.$path.'/.well-known/openid-configuration',
            ];

        foreach ($urls as $url) {
            $metadata = $this->requests->getJson($url);

            if ($metadata === null) {
                continue;
            }

            $named = $metadata['issuer'] ?? null;

            if (! is_string($named) || ! self::isSameIssuer($named, $issuer)) {
                throw ConnectionSignInFailed::because(__('The authorization server\'s settings name a different issuer, so Nexus won\'t sign in with them.'));
            }

            return $metadata;
        }

        throw ConnectionSignInFailed::because(__('Nexus couldn\'t find the settings of the server\'s authorization server.'));
    }

    /**
     * A URL's scheme, host and port, lowercased, with the default port left out.
     */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $defaultPort = ['https' => 443, 'http' => 80][$scheme] ?? null;

        return $scheme.'://'.strtolower($parts['host']).($port === null || $port === $defaultPort ? '' : ':'.$port);
    }

    /**
     * Whether a URL is a well-formed HTTPS URL. parse_url() accepts some
     * that Laravel's Uri refuses with an exception quoting them, such as an
     * unclosed IPv6 host, and the sign-in page's URL is built with Uri.
     */
    private function isHttpsUrl(string $url): bool
    {
        return str_starts_with(self::origin($url), 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * The strings in a list member of a metadata document.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private function strings(array $metadata, string $key): array
    {
        return array_values(array_filter(Arr::wrap($metadata[$key] ?? []), is_string(...)));
    }
}
