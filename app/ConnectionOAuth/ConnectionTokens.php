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
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Keeps OAuth Connections supplied with a current access token.
 *
 * Renewal is single-flight per Connection: it holds the Connection's
 * SignInLock, and the request that gets the lock reads the tokens again
 * before deciding, so a request that waited uses the token another one just
 * stored instead of renewing it a second time. That matters for servers that
 * rotate refresh tokens: replaying a used one can end the whole sign-in.
 *
 * Every other change to a Connection's sign-in (a new sign-in, a client the
 * server registered, a new server) holds the same lock. As a last guard, for
 * a renewal that outlived its lock, renewed tokens are only stored while the
 * Connection still has the server, settings and credentials the renewal
 * started from; otherwise they belong to a sign-in that no longer applies.
 */
final readonly class ConnectionTokens
{
    /**
     * Renew an access token this many seconds before it expires.
     */
    private const int LEEWAY_SECONDS = 60;

    /**
     * The columns that say which server a Connection signs in to and how:
     * the URL, the sign-in method, its settings and its encrypted
     * credentials, whose ciphertext changes with every new value.
     *
     * @var list<string>
     */
    private const array SIGN_IN_COLUMNS = ['url', 'auth_type', 'settings', 'secrets'];

    public function __construct(
        private OAuthClients $clients,
        private TokenEndpoint $tokenEndpoint,
        private SignInLock $signInLock,
    ) {}

    /**
     * The Connection's access token for a request to its server, renewed
     * first when it has expired or is about to. A renewed token, and the
     * refresh token a server rotated, are stored on the Connection (which
     * must be saved; it is re-read).
     *
     * The token is only ever for the server it will be sent to: once the
     * Connection signs in somewhere else (it moved to another server, perhaps
     * signing in there too, or stopped using OAuth), a request still bound
     * for its old server gets no token at all.
     *
     * When the server refuses to renew, the sign-in has ended: its tokens are
     * forgotten and the Connection needs sign-in, with a last error saying
     * so. A renewal that fails for a reason that may pass, such as the
     * server not answering, keeps the sign-in.
     *
     * With a deadline, renewing waits for the lock and the server only
     * until then, and fails as timed out once it has passed.
     *
     * @param  string  $serverUrl  The server the request goes to: the URL its session was opened with.
     * @param  int|null  $deadline  When the request's time is up, in milliseconds since the epoch.
     *
     * @throws DownstreamRequestFailed
     */
    public function accessToken(Connection $connection, string $serverUrl, ?int $deadline = null): string
    {
        if (! $this->signsInTo($connection, $serverUrl)) {
            throw DownstreamRequestFailed::notSignedIn();
        }

        $token = $this->currentToken($connection);

        if ($token !== null) {
            return $token;
        }

        if (! $connection->hasAccessToken()) {
            throw DownstreamRequestFailed::notSignedIn();
        }

        $left = $this->millisecondsLeft($deadline);
        $wait = $left === null ? null : intdiv($left, 1000);

        try {
            return $this->signInLock->hold($connection, function () use ($connection, $serverUrl, $deadline): string {
                if (! $this->signsInTo($connection, $serverUrl)) {
                    throw DownstreamRequestFailed::notSignedIn();
                }

                return $this->currentToken($connection) ?? $this->renew($connection, $deadline);
            }, $wait);
        } catch (ModelNotFoundException) {
            throw DownstreamRequestFailed::notSignedIn();
        } catch (LockTimeoutException) {
            throw $wait !== null && $wait < SignInLock::WAIT_SECONDS ? DownstreamRequestFailed::timedOut() : DownstreamRequestFailed::renewalBusy();
        }
    }

    /**
     * Store the tokens of a new sign-in, and the settings that say how to
     * renew them, holding the Connection's SignInLock, so a renewal of the
     * previous sign-in that is still running can't overwrite them. The
     * Connection is re-read first, and the sign-in must still apply to it
     * (the same server and client); it is then pending until its tools load,
     * and labelled with the account the token response named, if any, since
     * the user may have signed in to another account.
     *
     * @param  Closure(): bool  $stillApplies  Whether the sign-in still applies to the Connection as re-read.
     * @param  array<string, string>  $signIn  The settings describing the sign-in (see Connection::OAUTH_SIGN_IN_SETTINGS).
     * @return bool Whether the tokens were stored.
     *
     * @throws LockTimeoutException when another change holds the lock too long
     */
    public function storeSignIn(Connection $connection, Closure $stillApplies, IssuedTokens $tokens, array $signIn): bool
    {
        try {
            return $this->signInLock->hold($connection, function () use ($connection, $stillApplies, $tokens, $signIn): bool {
                if (! $stillApplies()) {
                    return false;
                }

                $connection->forgetOAuthSignIn();
                $connection->settings = [...$connection->settings ?? [], ...$signIn];
                $connection->secrets->put([
                    'access_token' => $tokens->accessToken,
                    'refresh_token' => $tokens->refreshToken,
                    'expires_at' => $tokens->expiresAt,
                ]);
                $connection->forceFill([
                    'status' => ConnectionStatus::Pending,
                    'last_error' => null,
                    'account_identity' => $tokens->accountIdentity,
                ])->save();

                return true;
            });
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    /**
     * The milliseconds left before the deadline, or null without one.
     *
     * @throws DownstreamRequestFailed when the deadline has passed
     */
    private function millisecondsLeft(?int $deadline): ?int
    {
        if ($deadline === null) {
            return null;
        }

        $milliseconds = $deadline - Date::now()->getTimestampMs();

        return $milliseconds > 0 ? $milliseconds : throw DownstreamRequestFailed::timedOut();
    }

    /**
     * Whether the Connection signs in with OAuth to this server.
     */
    private function signsInTo(Connection $connection, string $serverUrl): bool
    {
        return $connection->usesOAuth() && $connection->url === $serverUrl;
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
     * Renew the access token with the refresh token, and store the result,
     * with the account the server named, if it named one.
     *
     * @throws DownstreamRequestFailed
     */
    private function renew(Connection $connection, ?int $deadline): string
    {
        $refreshToken = $connection->secrets->get('refresh_token');
        $client = $this->clients->forRenewal($connection);
        $endpoint = $connection->setting('token_endpoint');

        if (! is_string($refreshToken) || $refreshToken === '' || ! $client instanceof OAuthClient || $endpoint === null) {
            $this->endSignIn($connection, $failed = DownstreamRequestFailed::signInExpired());

            throw $failed;
        }

        try {
            $left = $this->millisecondsLeft($deadline);
            $tokens = $this->tokenEndpoint->renew($endpoint, $client, $refreshToken, $connection->setting('resource') ?? $connection->url, $left === null ? null : $left / 1000);
        } catch (DownstreamRequestFailed $failed) {
            if ($failed->failure === DownstreamFailure::NeedsSignIn) {
                $this->endSignIn($connection, $failed);

                throw $failed;
            }

            throw $deadline !== null && $deadline <= Date::now()->getTimestampMs() ? DownstreamRequestFailed::timedOut() : $failed;
        }

        $stored = $this->whileUnchanged($connection, function () use ($connection, $tokens, $refreshToken): void {
            $connection->secrets->put([
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken ?? $refreshToken,
                'expires_at' => $tokens->expiresAt,
            ]);
            $connection->account_identity = $tokens->accountIdentity ?? $connection->account_identity;
            $connection->save();
        });

        if (! $stored) {
            throw DownstreamRequestFailed::notSignedIn();
        }

        return $tokens->accessToken;
    }

    /**
     * Forget a sign-in the server won't renew, and say why, unless the
     * Connection has changed since.
     */
    private function endSignIn(Connection $connection, DownstreamRequestFailed $failed): void
    {
        $this->whileUnchanged($connection, function () use ($connection, $failed): void {
            $connection->forgetOAuthSignIn();
            $connection->forceFill(['status' => ConnectionStatus::NeedsAuth, 'last_error' => $failed->getMessage()])->save();
        });
    }

    /**
     * Write to the Connection in one transaction holding its row, but only
     * if it still has the server, settings and credentials it was read with.
     * A change that didn't wait for the lock, because a hung renewal
     * outlived it, wins over the renewal.
     *
     * @param  Closure(): void  $write
     * @return bool Whether the write ran.
     */
    private function whileUnchanged(Connection $connection, Closure $write): bool
    {
        $read = $this->signInOf($connection);

        return DB::transaction(function () use ($connection, $read, $write): bool {
            $current = Connection::query()->whereKey($connection->id)->lockForUpdate()->first(['id', ...self::SIGN_IN_COLUMNS]);

            if ($current === null || $this->signInOf($current) !== $read) {
                return false;
            }

            $write();

            return true;
        });
    }

    /**
     * The Connection's sign-in columns as stored, ciphertext and all.
     *
     * @return list<mixed>
     */
    private function signInOf(Connection $connection): array
    {
        return array_map(fn (string $column): mixed => $connection->getRawOriginal($column), self::SIGN_IN_COLUMNS);
    }
}
