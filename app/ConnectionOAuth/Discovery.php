<?php

declare(strict_types=1);

namespace App\ConnectionOAuth;

/**
 * What discovery found out about signing in to a Connection's server: the
 * resource Nexus asks for access to, and its authorization server.
 */
final readonly class Discovery
{
    /**
     * @param  string  $resource  The resource to name in sign-in and token requests: the server's URL, or the shorter one its metadata advertises.
     * @param  list<string>  $scopesSupported  The scopes the server's protected-resource metadata lists.
     * @param  list<string>  $tokenEndpointAuthMethods  How the authorization server lets clients authenticate at its token endpoint; empty when it doesn't say.
     * @param  bool  $supportsMetadataDocuments  Whether it accepts a Client ID Metadata Document URL as a client ID.
     * @param  bool  $returnsIssuer  Whether it names itself (`iss`) when it sends the user back.
     */
    public function __construct(
        public string $resource,
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public ?string $registrationEndpoint,
        public array $scopesSupported,
        public array $tokenEndpointAuthMethods,
        public bool $supportsMetadataDocuments,
        public bool $returnsIssuer,
    ) {}

    /**
     * How a client with this secret, or none, authenticates at the token
     * endpoint: with the secret in the form, unless the server lists only
     * HTTP Basic. (GitHub lists nothing and wants the form.) A client
     * without a secret only sends its ID.
     */
    public function tokenAuthMethodFor(?string $clientSecret): string
    {
        if ($clientSecret === null || $clientSecret === '') {
            return 'none';
        }

        $methods = $this->tokenEndpointAuthMethods;

        return $methods !== [] && ! in_array('client_secret_post', $methods, true) && in_array('client_secret_basic', $methods, true)
            ? 'client_secret_basic'
            : 'client_secret_post';
    }
}
