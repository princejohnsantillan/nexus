<?php

namespace App\Mcp\Downstream;

use App\Enums\ConnectionStatus;
use App\Models\Connection;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Client\Exceptions\OAuthException;
use Laravel\Mcp\Client\OAuth\TokenSet;

/**
 * Keeps OAuth connections supplied with a valid access token.
 *
 * Refreshes are single-flight per connection: a cache lock serialises them,
 * and whoever wins saves the new tokens before releasing it. This matters for
 * servers like Notion that rotate the refresh token on every use and revoke
 * the whole grant if an old one is replayed.
 */
class OAuthTokens
{
    /** Refresh this many seconds before the token actually expires. */
    protected const LEEWAY = 60;

    public function __construct(protected OAuthClients $clients) {}

    public function accessToken(Connection $connection): string
    {
        $token = $connection->secret('access_token');

        if (! is_string($token) || $token === '') {
            throw new ConnectionNeedsAuth($connection, "The [{$connection->name}] connection has not been signed in yet.");
        }

        return $this->expiresSoon($connection) ? $this->refresh($connection, $token) : $token;
    }

    /**
     * Refresh the access token, unless another request already replaced the
     * stale one while we waited for the lock.
     */
    public function refresh(Connection $connection, ?string $staleToken = null): string
    {
        return Cache::lock("nexus:connection:{$connection->id}:refresh", 30)->block(25, function () use ($connection, $staleToken): string {
            $connection->refresh();

            $current = $connection->secret('access_token');

            if (is_string($current) && $current !== '' && $current !== $staleToken && ! $this->expiresSoon($connection)) {
                return $current;
            }

            $refreshToken = $connection->secret('refresh_token');

            if (! is_string($refreshToken) || $refreshToken === '') {
                $this->needsAuth($connection, 'The access token expired and the server did not issue a refresh token.');
            }

            try {
                $tokens = $this->clients->for($connection)->refreshCredentials($refreshToken);
            } catch (OAuthException $exception) {
                $this->needsAuth($connection, 'Refreshing the access token failed: '.$exception->getMessage());
            }

            $this->store($connection, $tokens, keepRefreshToken: $refreshToken);

            return $tokens->accessToken;
        });
    }

    /**
     * Save a token set. The client it was issued to is remembered only when
     * the server registered that client for this connection; apps the user
     * or the deployment configured are always read from their settings, so
     * rotating a deployment app's secret takes effect everywhere.
     */
    public function store(Connection $connection, TokenSet $tokens, ?string $keepRefreshToken = null): void
    {
        $registered = $this->clients->usesRegisteredClient($connection);

        $connection->putSecrets([
            'access_token' => $tokens->accessToken,
            // Servers that don't rotate refresh tokens omit them on refresh.
            'refresh_token' => $tokens->refreshToken ?? $keepRefreshToken,
            'expires_at' => $tokens->expiresAt,
            'token_type' => $tokens->tokenType,
            'client_id' => $registered ? ($tokens->clientId ?? $connection->secret('client_id')) : null,
            'client_secret' => $registered ? ($tokens->clientSecret ?? $connection->secret('client_secret')) : null,
        ]);

        $connection->forceFill(['status' => ConnectionStatus::Active, 'status_message' => null])->save();
    }

    protected function expiresSoon(Connection $connection): bool
    {
        $expiresAt = $connection->secret('expires_at');

        return is_int($expiresAt) && $expiresAt - self::LEEWAY <= time();
    }

    protected function needsAuth(Connection $connection, string $reason): never
    {
        $connection->markStatus(ConnectionStatus::NeedsAuth, $reason);

        throw new ConnectionNeedsAuth($connection, $reason);
    }
}
