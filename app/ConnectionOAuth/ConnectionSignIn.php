<?php

declare(strict_types=1);

namespace App\ConnectionOAuth;

use App\Actions\RefreshCatalog;
use App\Downstream\DownstreamClient;
use App\Exceptions\ConnectionSignInFailed;
use App\Exceptions\DownstreamRequestFailed;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Laravel\Mcp\Client\OAuth\Pkce;
use Laravel\Mcp\Client\OAuth\WwwAuthenticateChallenge;
use SensitiveParameter;

/**
 * Signs Connections in to their servers with OAuth, as Nexus's client:
 *
 *     $url = $signIn->start($connection);              // send the user here
 *     $connection = $signIn->finish($user, $query);    // when they come back to the callback
 *
 * Starting asks the server how to sign in (its 401 challenge), discovers its
 * authorization server, picks the client to sign in as (registering one if
 * need be), and remembers the sign-in in the session by a random state. The
 * user approves Nexus on the server's own page, which sends them back to the
 * one callback URL every Connection shares. Finishing checks the state, the
 * issuer and the Connection, exchanges the code (with PKCE), stores the
 * tokens encrypted, and loads the Connection's tools.
 */
final readonly class ConnectionSignIn
{
    public function __construct(
        private DownstreamClient $downstream,
        private AuthorizationServerDiscovery $discovery,
        private OAuthClients $clients,
        private TokenEndpoint $tokenEndpoint,
        private ConnectionTokens $tokens,
        private PendingSignIns $pending,
        private NexusClient $nexus,
        private RefreshCatalog $refreshCatalog,
    ) {}

    /**
     * Start signing an OAuth Connection in.
     *
     * @return string The server's sign-in page, with the request for Nexus's access in its query.
     *
     * @throws ConnectionSignInFailed
     */
    public function start(Connection $connection): string
    {
        $challenge = $this->challenge($connection);
        $server = $this->discovery->discover($connection->url, $challenge);
        $scope = $this->scope($connection, $server, $challenge);
        $client = $this->clients->forSignIn($connection, $server, $scope);

        $pkce = Pkce::generate();
        $state = Str::random(40);

        $this->pending->remember($state, new PendingSignIn(
            connectionId: $connection->id,
            serverUrl: $connection->url,
            verifier: $pkce->verifier,
            clientSource: $client->source,
            clientId: $client->id,
            tokenAuthMethod: $client->authMethod,
            tokenEndpoint: $server->tokenEndpoint,
            issuer: $server->issuer,
            returnsIssuer: $server->returnsIssuer,
            resource: $server->resource,
            scopes: $scope,
            redirectUri: $this->nexus->callbackUrl(),
        ));

        return (string) Uri::of($server->authorizationEndpoint)->withQuery(array_filter([
            'response_type' => 'code',
            'client_id' => $client->id,
            'redirect_uri' => $this->nexus->callbackUrl(),
            'state' => $state,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'scope' => $scope,
            'resource' => $server->resource,
            'prompt' => $connection->connector()?->selectAccount === true ? 'select_account' : null,
        ], fn (?string $value): bool => $value !== null && $value !== ''));
    }

    /**
     * Finish the sign-in the user came back from, with the callback's query.
     * The Connection must be the user's own and still sign in with OAuth to
     * the server the sign-in started with.
     *
     * @param  array<array-key, mixed>  $query
     * @return Connection The Connection, signed in, with its tools loaded if they could be.
     *
     * @throws ConnectionSignInFailed naming the Connection when Nexus knows it
     */
    public function finish(User $user, #[SensitiveParameter] array $query): Connection
    {
        $state = $query['state'] ?? null;
        $pending = is_string($state) && $state !== '' ? $this->pending->pull($state) : null;
        $connection = $pending instanceof PendingSignIn ? $user->connections()->find($pending->connectionId) : null;

        if (! $pending instanceof PendingSignIn || ! $connection instanceof Connection) {
            throw ConnectionSignInFailed::because(__('This sign-in has expired or was already used. Start it again from the Connection.'));
        }

        try {
            $this->checkReturn($connection, $pending, $query);

            $code = $query['code'] ?? null;

            if (! is_string($code) || $code === '') {
                throw ConnectionSignInFailed::because(__('The server\'s sign-in page sent you back without an authorization code.'));
            }

            $tokens = $this->tokenEndpoint->exchangeCode(
                $pending->tokenEndpoint,
                $this->clients->resume($connection, $pending->clientSource, $pending->clientId, $pending->tokenAuthMethod),
                $code,
                $pending->verifier,
                $pending->redirectUri,
                $pending->resource,
            );

            $this->store($connection, $pending, $tokens);
        } catch (ConnectionSignInFailed $failed) {
            throw $failed->for($connection);
        }

        $this->refreshCatalog->handle($connection);

        return $connection;
    }

    /**
     * How the server refuses Nexus without credentials.
     *
     * @throws ConnectionSignInFailed
     */
    private function challenge(Connection $connection): WwwAuthenticateChallenge
    {
        try {
            return $this->downstream->signInChallenge($connection)
                ?? throw ConnectionSignInFailed::because(__('This server lets Nexus in without signing in, so it doesn\'t need OAuth. Choose No auth instead.'));
        } catch (DownstreamRequestFailed $failed) {
            throw ConnectionSignInFailed::because(__('Nexus couldn\'t ask the server how to sign in. :reason', ['reason' => $failed->getMessage()]));
        }
    }

    /**
     * The scopes to ask for: the connector's, else the ones the server's
     * challenge asks for, else every scope its metadata lists, else none.
     */
    private function scope(Connection $connection, Discovery $server, WwwAuthenticateChallenge $challenge): ?string
    {
        $connectorScopes = $connection->connector()->scopes ?? [];

        return match (true) {
            $connectorScopes !== [] => implode(' ', $connectorScopes),
            is_string($challenge->scope) && trim($challenge->scope) !== '' => trim($challenge->scope),
            $server->scopesSupported !== [] => implode(' ', $server->scopesSupported),
            default => null,
        };
    }

    /**
     * Check the user came back from the sign-in that is pending: for a
     * Connection that still signs in with OAuth to the same server, from
     * the authorization server Nexus sent them to, with its approval.
     *
     * @param  array<array-key, mixed>  $query
     *
     * @throws ConnectionSignInFailed
     */
    private function checkReturn(Connection $connection, PendingSignIn $pending, array $query): void
    {
        if (! $connection->usesOAuth() || $connection->url !== $pending->serverUrl) {
            throw ConnectionSignInFailed::because(__('The Connection\'s server or sign-in changed while you were signing in. Start again.'));
        }

        $issuer = $query['iss'] ?? null;

        if ($issuer !== null || $pending->returnsIssuer) {
            if (! is_string($issuer) || ! AuthorizationServerDiscovery::isSameIssuer($issuer, $pending->issuer)) {
                throw ConnectionSignInFailed::because(__('The sign-in came back from a different server than Nexus sent you to, so Nexus ignored it.'));
            }
        }

        if (isset($query['error'])) {
            throw ConnectionSignInFailed::denied($query['error']);
        }
    }

    /**
     * Store the new sign-in's tokens, and how to renew them.
     *
     * @throws ConnectionSignInFailed
     */
    private function store(Connection $connection, PendingSignIn $pending, IssuedTokens $tokens): void
    {
        $signIn = array_filter([
            'client_source' => $pending->clientSource->value,
            'client_id' => $pending->clientId,
            'token_auth_method' => $pending->tokenAuthMethod,
            'token_endpoint' => $pending->tokenEndpoint,
            'issuer' => $pending->issuer,
            'resource' => $pending->resource,
            'scopes' => $pending->scopes,
            'signed_in_at' => now()->format('Y-m-d\TH:i:s.uP'),
        ], fn (?string $value): bool => $value !== null);

        try {
            $stored = $this->tokens->storeSignIn($connection, $pending->serverUrl, $tokens, $signIn);
        } catch (DownstreamRequestFailed) {
            $stored = false;
        }

        if (! $stored) {
            throw ConnectionSignInFailed::because(__('The Connection changed while you were signing in, so Nexus didn\'t keep the sign-in. Start again.'));
        }
    }
}
