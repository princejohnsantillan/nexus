<?php

namespace App\Mcp\Downstream;

use Laravel\Mcp\Client\OAuth\AuthServerMetadata;
use Laravel\Mcp\Client\OAuth\ClientRegistration;
use Laravel\Mcp\Client\OAuth\DynamicClientRegistration;
use Laravel\Mcp\Client\OAuth\OAuthClient;
use Throwable;

/**
 * The laravel/mcp OAuth client with two fixes for talking to arbitrary
 * servers:
 *
 * - Scopes follow the MCP spec: the 401 challenge's scope, then the
 *   connection's configured scope, then the server's advertised scopes. The
 *   stock client falls back to `mcp:use`, which only Laravel servers know.
 * - Dynamic client registration identifies itself as Nexus.
 */
class NexusOAuthClient extends OAuthClient
{
    protected function resolveScope(): ?string
    {
        if (filled($this->challengeScope)) {
            return $this->challengeScope;
        }

        if (filled($this->config->scope)) {
            return $this->config->scope;
        }

        try {
            $advertised = $this->discover()->scopesSupported;
        } catch (Throwable) {
            return null;
        }

        return $advertised === [] ? null : implode(' ', $advertised);
    }

    protected function register(AuthServerMetadata $metadata, string $redirectUri): ClientRegistration
    {
        if ($metadata->registrationEndpoint === null) {
            return parent::register($metadata, $redirectUri);
        }

        return (new DynamicClientRegistration)->register(
            $metadata->registrationEndpoint,
            $redirectUri,
            $this->resolveScope(),
            clientName: config('app.name'),
            applicationType: $this->applicationType($redirectUri),
            tokenEndpointAuthMethod: $this->resolveTokenAuthMethod($metadata, 'confidential'),
        );
    }
}
