<?php

namespace App\Mcp\Downstream;

use App\Models\Connection;
use Laravel\Mcp\Client\OAuth\OAuthConfig;

/**
 * Builds the OAuth client Nexus uses to sign in to a connection's server.
 *
 * Client registration is tried in the MCP spec's order: a client id the user
 * brought (needed for Slack and GitHub), then Nexus's Client ID Metadata
 * Document, then dynamic client registration.
 */
class OAuthClients
{
    public function for(Connection $connection, ?string $resourceMetadataUrl = null, ?string $challengeScope = null): NexusOAuthClient
    {
        return (new NexusOAuthClient(
            new OAuthConfig(
                clientId: $connection->secret('client_id') ?? $connection->setting('oauth_client_id'),
                clientSecret: $connection->secret('client_secret') ?? $connection->secret('oauth_client_secret'),
                scope: $connection->setting('oauth_scope'),
                redirectUri: route('oauth.callback'),
            ),
            $connection->url,
            $resourceMetadataUrl,
            $challengeScope,
            clientIdMetadataUrl: route('oauth.client-metadata'),
        ))->scopedTo($connection->id);
    }
}
