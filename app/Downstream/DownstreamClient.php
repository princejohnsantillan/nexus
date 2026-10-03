<?php

declare(strict_types=1);

namespace App\Downstream;

use App\ConnectionOAuth\ConnectionTokens;
use App\Enums\ConnectionAuthType;
use App\Enums\DownstreamFailure;
use App\Exceptions\DownstreamRequestFailed;
use App\Models\Connection;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Client\OAuth\WwwAuthenticateChallenge;
use Laravel\Mcp\Schema\Implementation;

/**
 * Opens sessions to Connections' MCP servers, signed in the way each
 * Connection says, with the timeouts from `nexus.downstream`:
 *
 *     $tools = $downstream->session($connection)->listTools();
 *
 * Opening a session sends nothing; it connects on its first request. An
 * OAuth Connection's access token is read for every request, and renewed
 * first when it has expired (see ConnectionTokens); `$session->signIn()`
 * does that alone, to learn whether the session can sign in before
 * anything is sent. A session stays bound to
 * the server it was opened for: once the Connection signs in somewhere else,
 * the session gets no token and fails as needing sign-in. A session's
 * requests, and any renewal of its token, take at most the configured call
 * timeout altogether from when it is opened, however short each request's
 * own limit is.
 */
final readonly class DownstreamClient
{
    /**
     * @param  float|null  $callTimeout  Seconds to wait for each request other than the handshake; null for `nexus.downstream.call_timeout`.
     */
    public function __construct(
        private ConnectionTokens $tokens,
        private ?float $callTimeout = null,
    ) {}

    /**
     * A client like this one that waits at most this many seconds for each
     * request other than the handshake (or the configured call timeout,
     * when that is shorter), for work that must end by a deadline, such as
     * a background refresh.
     */
    public function withCallTimeout(float $seconds): self
    {
        return new self($this->tokens, min($seconds, $this->callTimeout()));
    }

    public function session(Connection $connection): DownstreamSession
    {
        return $this->open($connection, signedIn: true);
    }

    /**
     * Ask the Connection's server, without signing in, how to sign in: the
     * challenge it refuses Nexus with, or null when it lets Nexus list its
     * tools without signing in.
     *
     * @throws DownstreamRequestFailed when the server fails in any other way
     */
    public function signInChallenge(Connection $connection): ?WwwAuthenticateChallenge
    {
        try {
            $this->open($connection, signedIn: false)->listTools();
        } catch (DownstreamRequestFailed $failed) {
            if ($failed->failure === DownstreamFailure::NeedsSignIn && $failed->challenge instanceof WwwAuthenticateChallenge) {
                return $failed->challenge;
            }

            throw $failed;
        }

        return null;
    }

    private function open(Connection $connection, bool $signedIn): DownstreamSession
    {
        $serverUrl = $connection->url;
        $deadline = Date::now()->getTimestampMs() + (int) round(config()->float('nexus.downstream.call_timeout') * 1000);

        $transport = new DownstreamTransport(
            $serverUrl,
            connectTimeout: config()->float('nexus.downstream.connect_timeout'),
            callTimeout: $this->callTimeout(),
            deadline: $deadline,
        );

        if ($signedIn && $connection->auth_type === ConnectionAuthType::Header) {
            $transport->withHeaders([$connection->headerName() => $connection->headerValue()]);
        }

        $accessToken = null;

        if ($signedIn && $connection->auth_type === ConnectionAuthType::OAuth) {
            $accessToken = fn (): string => $this->tokens->accessToken($connection, $serverUrl, $deadline);
            $transport->withToken($accessToken);
        }

        $client = new DownstreamMcpClient($transport, new Implementation(
            name: 'nexus',
            version: '1.0.0',
            title: config()->string('app.name'),
        ));

        return new DownstreamSession($client, $transport, $accessToken);
    }

    private function callTimeout(): float
    {
        return $this->callTimeout ?? config()->float('nexus.downstream.call_timeout');
    }
}
