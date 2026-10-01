<?php

namespace App\Mcp\Downstream;

use App\Models\Connection;
use Laravel\Mcp\Client\OAuth\OAuthConfig;

/**
 * Builds the OAuth client Nexus uses to sign in to a connection's server.
 */
class OAuthClients
{
    public function for(Connection $connection, ?string $resourceMetadataUrl = null, ?string $challengeScope = null): NexusOAuthClient
    {
        [$clientId, $clientSecret] = $this->credentials($connection);

        return (new NexusOAuthClient(
            new OAuthConfig(
                clientId: $clientId,
                clientSecret: $clientSecret,
                scope: $connection->setting('oauth_scope') ?? $connection->connectorDefinition()?->scope,
                redirectUri: route('oauth.callback'),
            ),
            $connection->setting('oauth_resource') ?? $connection->url,
            $resourceMetadataUrl,
            $challengeScope,
            new NexusAuthServerDiscovery,
            clientIdMetadataUrl: route('oauth.client-metadata'),
        ))->scopedTo($connection->id);
    }

    /**
     * The OAuth client to sign in as, first match wins:
     *
     * 1. an app the user brought for this connection;
     * 2. this deployment's app for the connector (Slack, GitHub);
     * 3. the client the server registered for this connection last time.
     *
     * With none of these, the server registers Nexus on sign-in, through its
     * Client ID Metadata Document or dynamic client registration.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function credentials(Connection $connection): array
    {
        if (filled($connection->setting('oauth_client_id'))) {
            return [$connection->setting('oauth_client_id'), $connection->secret('oauth_client_secret')];
        }

        if (($app = $connection->connectorDefinition()?->deploymentClient()) !== null) {
            return [$app['client_id'], $app['client_secret']];
        }

        return [$connection->secret('client_id'), $connection->secret('client_secret')];
    }

    /**
     * Whether the connection signs in with a client the server registered
     * for it, which is the only kind worth remembering per connection.
     */
    public function usesRegisteredClient(Connection $connection): bool
    {
        return blank($connection->setting('oauth_client_id'))
            && $connection->connectorDefinition()?->deploymentClient() === null;
    }
}
