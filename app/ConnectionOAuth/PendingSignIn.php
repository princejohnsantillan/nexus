<?php

declare(strict_types=1);

namespace App\ConnectionOAuth;

use App\Concerns\KeepsSecretsInMemory;
use App\Enums\OAuthClientSource;
use SensitiveParameter;

/**
 * A sign-in that has sent the user to a server's sign-in page and waits
 * for them to come back: what Nexus needs to finish it.
 */
final readonly class PendingSignIn
{
    use KeepsSecretsInMemory;

    /**
     * @param  string  $serverUrl  The Connection's server when the sign-in started; it must not change before it finishes.
     * @param  string  $verifier  The PKCE code verifier.
     * @param  string|null  $scopes  The scopes asked for.
     * @param  string|null  $returnTo  The public id of the Star the user is adding the Connection to, to go back to once it's signed in (ReturnToStar).
     */
    public function __construct(
        public int $connectionId,
        public string $serverUrl,
        #[SensitiveParameter] public string $verifier,
        public OAuthClientSource $clientSource,
        public string $clientId,
        public string $tokenAuthMethod,
        public string $tokenEndpoint,
        public string $issuer,
        public bool $returnsIssuer,
        public string $resource,
        public ?string $scopes,
        public string $redirectUri,
        public ?string $returnTo = null,
    ) {}

    /**
     * @return array<string, int|string|bool|null>
     */
    public function toArray(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'server_url' => $this->serverUrl,
            'verifier' => $this->verifier,
            'client_source' => $this->clientSource->value,
            'client_id' => $this->clientId,
            'token_auth_method' => $this->tokenAuthMethod,
            'token_endpoint' => $this->tokenEndpoint,
            'issuer' => $this->issuer,
            'returns_issuer' => $this->returnsIssuer,
            'resource' => $this->resource,
            'scopes' => $this->scopes,
            'redirect_uri' => $this->redirectUri,
            'return_to' => $this->returnTo,
        ];
    }

    /**
     * The pending sign-in toArray() wrote, or null for anything else.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }

        $strings = ['server_url', 'verifier', 'client_id', 'token_auth_method', 'token_endpoint', 'issuer', 'resource', 'redirect_uri'];

        foreach ($strings as $key) {
            if (! is_string($data[$key] ?? null)) {
                return null;
            }
        }

        $connectionId = $data['connection_id'] ?? null;
        $clientSource = OAuthClientSource::tryFrom(is_string($data['client_source'] ?? null) ? $data['client_source'] : '');
        $returnsIssuer = $data['returns_issuer'] ?? null;
        $scopes = $data['scopes'] ?? null;
        $returnTo = $data['return_to'] ?? null;

        if (! is_int($connectionId) || ! $clientSource instanceof OAuthClientSource || ! is_bool($returnsIssuer) || ($scopes !== null && ! is_string($scopes))) {
            return null;
        }

        /** @var array<string, string> $data */
        return new self(
            connectionId: $connectionId,
            serverUrl: $data['server_url'],
            verifier: $data['verifier'],
            clientSource: $clientSource,
            clientId: $data['client_id'],
            tokenAuthMethod: $data['token_auth_method'],
            tokenEndpoint: $data['token_endpoint'],
            issuer: $data['issuer'],
            returnsIssuer: $returnsIssuer,
            resource: $data['resource'],
            scopes: $scopes,
            redirectUri: $data['redirect_uri'],
            returnTo: is_string($returnTo) ? $returnTo : null,
        );
    }
}
