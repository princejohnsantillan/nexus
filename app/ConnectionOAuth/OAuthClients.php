<?php

declare(strict_types=1);

namespace App\ConnectionOAuth;

use App\Enums\OAuthClientSource;
use App\Exceptions\ConnectionSignInFailed;
use App\Models\Connection;

/**
 * Which OAuth client Nexus signs in to a Connection's server as.
 *
 * Credentials the user or the deployment configured are always read from
 * where they are configured, never copied, so a rotated deployment secret
 * applies to every Connection at once. Only a client the server registered
 * for a Connection is stored on it, with its secret encrypted.
 */
final readonly class OAuthClients
{
    /**
     * How a client may authenticate at a token endpoint.
     *
     * @var list<string>
     */
    private const array AUTH_METHODS = ['client_secret_post', 'client_secret_basic', 'none'];

    public function __construct(
        private NexusClient $nexus,
        private OAuthRequests $requests,
    ) {}

    /**
     * The client to sign in as, the first that applies:
     *
     * 1. the user's own OAuth app, entered on the Connection;
     * 2. the deployment's OAuth app for the Connection's connector;
     * 3. Nexus's Client ID Metadata Document, when the server accepts one
     *    and Nexus is somewhere the server can fetch it from;
     * 4. a client the server registers for the Connection (RFC 7591): the
     *    one stored on it, when it was registered with this authorization
     *    server for the current callback URL, or else a new one, which is
     *    stored on the Connection (the Connection is saved).
     *
     * @param  string|null  $scope  The scopes the sign-in asks for, which a registration asks for too.
     *
     * @throws ConnectionSignInFailed when none applies, or the server refuses to register Nexus
     */
    public function forSignIn(Connection $connection, Discovery $server, ?string $scope): OAuthClient
    {
        $ownClientId = $connection->oauthClientId();

        if ($ownClientId !== null) {
            $secret = $this->secret($connection, 'oauth_client_secret');

            return new OAuthClient($ownClientId, $secret, OAuthClientSource::OwnApp, $server->tokenAuthMethodFor($secret));
        }

        $app = $connection->connector()?->deploymentApp();

        if ($app !== null) {
            return new OAuthClient($app['client_id'], $app['client_secret'], OAuthClientSource::DeploymentApp, $server->tokenAuthMethodFor($app['client_secret']));
        }

        if ($server->supportsMetadataDocuments && $this->nexus->canUseMetadataDocument()) {
            return new OAuthClient($this->nexus->metadataDocumentUrl(), null, OAuthClientSource::MetadataDocument, 'none');
        }

        return $this->registered($connection, $server) ?? $this->register($connection, $server, $scope);
    }

    /**
     * The client a sign-in was issued to, with its secret as configured
     * now, to finish the sign-in or renew its tokens. The secret is null
     * when that client is no longer configured, and the server then refuses.
     */
    public function resume(Connection $connection, OAuthClientSource $source, string $clientId, string $authMethod): OAuthClient
    {
        $app = $connection->connector()?->deploymentApp();

        $secret = match ($source) {
            OAuthClientSource::OwnApp => $connection->oauthClientId() === $clientId ? $this->secret($connection, 'oauth_client_secret') : null,
            OAuthClientSource::DeploymentApp => $app !== null && $app['client_id'] === $clientId ? $app['client_secret'] : null,
            OAuthClientSource::MetadataDocument => null,
            OAuthClientSource::Registered => $connection->setting('registered_client_id') === $clientId ? $this->secret($connection, 'registered_client_secret') : null,
        };

        return new OAuthClient($clientId, $secret, $source, $authMethod);
    }

    /**
     * The client the Connection's current sign-in was issued to, or null
     * when it isn't signed in.
     */
    public function forRenewal(Connection $connection): ?OAuthClient
    {
        $source = OAuthClientSource::tryFrom($connection->setting('client_source') ?? '');
        $clientId = $connection->setting('client_id');
        $authMethod = $connection->setting('token_auth_method');

        if ($source === null || $clientId === null || $authMethod === null) {
            return null;
        }

        return $this->resume($connection, $source, $clientId, $authMethod);
    }

    /**
     * The client the server registered for the Connection, if it is still
     * good for this authorization server and callback URL.
     */
    private function registered(Connection $connection, Discovery $server): ?OAuthClient
    {
        $clientId = $connection->setting('registered_client_id');
        $issuer = $connection->setting('registered_issuer');

        if ($clientId === null || $issuer === null || ! AuthorizationServerDiscovery::isSameIssuer($issuer, $server->issuer)
            || $connection->setting('registered_redirect_uri') !== $this->nexus->callbackUrl()) {
            return null;
        }

        $secret = $this->secret($connection, 'registered_client_secret');

        return new OAuthClient($clientId, $secret, OAuthClientSource::Registered, $connection->setting('registered_auth_method') ?? $server->tokenAuthMethodFor($secret));
    }

    /**
     * Register Nexus with the server for this Connection, and store the
     * client it registered on the Connection.
     *
     * @throws ConnectionSignInFailed
     */
    private function register(Connection $connection, Discovery $server, ?string $scope): OAuthClient
    {
        if ($server->registrationEndpoint === null) {
            throw ConnectionSignInFailed::because(__('The server doesn\'t let Nexus register itself. Register an OAuth app on it and enter its client ID instead.'));
        }

        $requestedMethod = $this->registrationAuthMethod($server);
        $callbackUrl = $this->nexus->callbackUrl();

        $response = $this->requests->postJson($server->registrationEndpoint, array_filter([
            'client_name' => config()->string('app.name'),
            'client_uri' => rtrim(config()->string('app.url'), '/').'/',
            'redirect_uris' => [$callbackUrl],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $requestedMethod,
            'scope' => $scope,
        ], fn (mixed $value): bool => $value !== null));

        $registration = OAuthRequests::json($response);
        $clientId = $registration['client_id'] ?? null;

        if (! $response->successful()) {
            throw ConnectionSignInFailed::because(__('The server refused to register Nexus as a client (HTTP :status).', ['status' => $response->status()]));
        }

        if (! is_string($clientId) || $clientId === '') {
            throw ConnectionSignInFailed::because(__('The server registered Nexus but didn\'t say its client ID.'));
        }

        $secret = $registration['client_secret'] ?? null;
        $secret = is_string($secret) && $secret !== '' ? $secret : null;
        $grantedMethod = $registration['token_endpoint_auth_method'] ?? $requestedMethod;
        $authMethod = $secret === null ? 'none' : (in_array($grantedMethod, self::AUTH_METHODS, true) ? $grantedMethod : $requestedMethod);

        $connection->settings = [
            ...$connection->settings ?? [],
            'registered_client_id' => $clientId,
            'registered_issuer' => $server->issuer,
            'registered_redirect_uri' => $callbackUrl,
            'registered_auth_method' => $authMethod,
        ];
        $connection->secrets->put(['registered_client_secret' => $secret]);
        $connection->save();

        return new OAuthClient($clientId, $secret, OAuthClientSource::Registered, $authMethod);
    }

    /**
     * How to ask the server to let the registered client authenticate: with
     * a secret in the form where it allows, else with HTTP Basic (the
     * default when it doesn't say), else as a public client.
     */
    private function registrationAuthMethod(Discovery $server): string
    {
        $methods = $server->tokenEndpointAuthMethods;

        return match (true) {
            in_array('client_secret_post', $methods, true) => 'client_secret_post',
            $methods === [] || in_array('client_secret_basic', $methods, true) => 'client_secret_basic',
            in_array('none', $methods, true) => 'none',
            default => 'client_secret_basic',
        };
    }

    private function secret(Connection $connection, string $key): ?string
    {
        $secret = $connection->secrets->get($key);

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
