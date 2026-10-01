<?php

declare(strict_types=1);

namespace App\ConnectionOAuth;

/**
 * Nexus as an OAuth client of Connections' servers: the one callback URL
 * every sign-in returns to, and the Client ID Metadata Document that
 * describes Nexus to servers that accept one in place of registration.
 *
 * Both URLs are built from `app.url`, not the current request, so they stay
 * the same however the site is reached: servers compare them exactly.
 */
final readonly class NexusClient
{
    /**
     * Top-level domains that only resolve on a private network or never, so
     * no server on the internet could fetch a document from them.
     *
     * @var list<string>
     */
    private const array PRIVATE_TOP_LEVEL_DOMAINS = ['test', 'local', 'localhost', 'internal', 'invalid', 'example', 'lan', 'home', 'arpa'];

    /**
     * Where every Connection's sign-in returns to.
     */
    public function callbackUrl(): string
    {
        return $this->absolute(route('oauth.callback', absolute: false));
    }

    /**
     * Where Nexus publishes its Client ID Metadata Document. A server that
     * accepts such documents takes this URL as Nexus's client ID.
     */
    public function metadataDocumentUrl(): string
    {
        return $this->absolute(route('oauth.client-metadata', absolute: false));
    }

    /**
     * The Client ID Metadata Document: Nexus as a public client that signs
     * in with PKCE and returns to the callback URL.
     *
     * @return array{client_id: string, client_name: string, client_uri: string, redirect_uris: list<string>, grant_types: list<string>, response_types: list<string>, token_endpoint_auth_method: string}
     */
    public function metadataDocument(): array
    {
        return [
            'client_id' => $this->metadataDocumentUrl(),
            'client_name' => config()->string('app.name'),
            'client_uri' => $this->absolute('/'),
            'redirect_uris' => [$this->callbackUrl()],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
    }

    /**
     * Whether servers on the internet can fetch the metadata document: Nexus
     * is served over HTTPS on a public host. A local site, such as one on
     * `.test`, has to register with each server instead.
     */
    public function canUseMetadataDocument(): bool
    {
        $url = parse_url(config()->string('app.url'));
        $host = is_array($url) && ($url['scheme'] ?? null) === 'https' ? strtolower(trim($url['host'] ?? '', '[]')) : '';

        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        $labels = explode('.', rtrim($host, '.'));

        return count($labels) > 1 && ! in_array(end($labels), self::PRIVATE_TOP_LEVEL_DOMAINS, true);
    }

    private function absolute(string $path): string
    {
        return rtrim(config()->string('app.url'), '/').$path;
    }
}
