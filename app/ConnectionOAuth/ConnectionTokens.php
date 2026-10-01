<?php

declare(strict_types=1);

namespace App\ConnectionOAuth;

use App\Enums\ConnectionStatus;
use App\Enums\DownstreamFailure;
use App\Exceptions\DownstreamRequestFailed;
use App\Models\Connection;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps OAuth Connections supplied with a current access token.
 *
 * Renewal is single-flight per Connection: a cache lock lets one request at
 * a time renew, and the request that gets the lock reads the tokens again
 * before deciding, so a request that waited uses the token another one just
 * stored instead of renewing it a second time. That matters for servers that
 * rotate refresh tokens: replaying a used one can end the whole sign-in.
 */
final readonly class ConnectionTokens
{
    /**
     * Renew an access token this many seconds before it expires.
     */
    private const int LEEWAY_SECONDS = 60;

    /**
     * How long one request may hold a Connection's lock, in seconds: well
     * over a token request's timeout.
     */
    private const int LOCK_SECONDS = 30;

    /**
     * How long another request waits for the lock, in seconds.
     */
    private const int WAIT_SECONDS = 15;

    public function __construct(
        private OAuthClients $clients,
        private TokenEndpoint $tokenEndpoint,
    ) {}

    /**
     * The Connection's access token, renewed first when it has expired or is
     * about to. A renewed token, and the refresh token a server rotated, are
     * stored on the Connection (which must be saved; it is re-read).
     *
     * When the server refuses to renew, the sign-in has ended: its tokens are
     * forgotten and the Connection needs sign-in, with a last error saying
     * so. A renewal that fails for a reason that may pass, such as the
     * server not answering, keeps the sign-in.
     *
     * @throws DownstreamRequestFailed
     */
    public function accessToken(Connection $connection): string
    {
        $token = $this->currentToken($connection);

        if ($token !== null) {
            return $token;
        }

        if (! $connection->hasAccessToken()) {
            throw DownstreamRequestFailed::notSignedIn();
        }

        return $this->whileLocked($connection, function () use ($connection): string {
            try {
                $connection->refresh();
            } catch (ModelNotFoundException) {
                throw DownstreamRequestFailed::notSignedIn();
            }

            if (! $connection->usesOAuth()) {
                throw DownstreamRequestFailed::notSignedIn();
            }

            return $this->currentToken($connection) ?? $this->renew($connection);
        });
    }

    /**
     * Store the tokens of a new sign-in, and the settings that say how to
     * renew them, holding the same lock as renewals, so a renewal of the
     * previous sign-in that is still running can't overwrite them. The
     * Connection is re-read first and must still sign in to the same
     * server; it is then pending until its tools load.
     *
     * @param  array<string, string>  $signIn  The settings describing the sign-in (see Connection::OAUTH_SIGN_IN_SETTINGS).
     * @return bool Whether the tokens were stored.
     *
     * @throws DownstreamRequestFailed when a renewal holds the lock too long
     */
    public function storeSignIn(Connection $connection, string $serverUrl, IssuedTokens $tokens, array $signIn): bool
    {
        return $this->whileLocked($connection, function () use ($connection, $serverUrl, $tokens, $signIn): bool {
            try {
                $connection->refresh();
            } catch (ModelNotFoundException) {
                return false;
            }

            if ($connection->url !== $serverUrl || ! $connection->usesOAuth()) {
                return false;
            }

            $connection->forgetOAuthSignIn();
            $connection->settings = [...$connection->settings ?? [], ...$signIn];
            $connection->secrets->put([
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'expires_at' => $tokens->expiresAt,
            ]);
            $connection->forceFill(['status' => ConnectionStatus::Pending, 'last_error' => null])->save();

            return true;
        });
    }

    /**
     * The stored access token, unless there is none or it is about to expire.
     */
    private function currentToken(Connection $connection): ?string
    {
        $token = $connection->secrets->get('access_token');
        $expiresAt = $connection->secrets->get('expires_at');

        if (! is_string($token) || $token === '' || (is_int($expiresAt) && $expiresAt - self::LEEWAY_SECONDS <= now()->getTimestamp())) {
            return null;
        }

        return $token;
    }

    /**
     * Renew the access token with the refresh token, and store the result.
     *
     * @throws DownstreamRequestFailed
     */
    private function renew(Connection $connection): string
    {
        $refreshToken = $connection->secrets->get('refresh_token');
        $client = $this->clients->forRenewal($connection);
        $endpoint = $connection->setting('token_endpoint');

        if (! is_string($refreshToken) || $refreshToken === '' || ! $client instanceof OAuthClient || $endpoint === null) {
            $this->endSignIn($connection, $failed = DownstreamRequestFailed::signInExpired());

            throw $failed;
        }

        try {
            $tokens = $this->tokenEndpoint->renew($endpoint, $client, $refreshToken, $connection->setting('resource') ?? $connection->url);
        } catch (DownstreamRequestFailed $failed) {
            if ($failed->failure === DownstreamFailure::NeedsSignIn) {
                $this->endSignIn($connection, $failed);
            }

            throw $failed;
        }

        $connection->secrets->put([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken ?? $refreshToken,
            'expires_at' => $tokens->expiresAt,
        ]);
        $connection->save();

        return $tokens->accessToken;
    }

    /**
     * Forget a sign-in the server won't renew, and say why.
     */
    private function endSignIn(Connection $connection, DownstreamRequestFailed $failed): void
    {
        $connection->forgetOAuthSignIn();
        $connection->forceFill(['status' => ConnectionStatus::NeedsAuth, 'last_error' => $failed->getMessage()])->save();
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws DownstreamRequestFailed when another request holds the lock too long
     */
    private function whileLocked(Connection $connection, Closure $callback): mixed
    {
        try {
            return Cache::lock("connections.{$connection->id}.oauth-tokens", self::LOCK_SECONDS)->block(self::WAIT_SECONDS, $callback);
        } catch (LockTimeoutException) {
            throw DownstreamRequestFailed::renewalBusy();
        }
    }
}
