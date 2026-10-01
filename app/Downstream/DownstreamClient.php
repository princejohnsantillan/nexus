<?php

declare(strict_types=1);

namespace App\Downstream;

use App\ConnectionOAuth\ConnectionTokens;
use App\Enums\ConnectionAuthType;
use App\Enums\DownstreamFailure;
use App\Exceptions\DownstreamRequestFailed;
use App\Models\Connection;
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
 * first when it has expired (see ConnectionTokens).
 */
final readonly class DownstreamClient
{
    public function __construct(private ConnectionTokens $tokens) {}

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
        $transport = new DownstreamTransport(
            $connection->url,
            connectTimeout: config()->float('nexus.downstream.connect_timeout'),
            callTimeout: config()->float('nexus.downstream.call_timeout'),
        );

        if ($signedIn && $connection->auth_type === ConnectionAuthType::Header) {
            $transport->withHeaders([$connection->headerName() => $connection->headerValue()]);
        }

        if ($signedIn && $connection->auth_type === ConnectionAuthType::OAuth) {
            $transport->withToken(fn (): string => $this->tokens->accessToken($connection));
        }

        $client = new DownstreamMcpClient($transport, new Implementation(
            name: 'nexus',
            version: '1.0.0',
            title: config()->string('app.name'),
        ));

        return new DownstreamSession($client, $transport);
    }
}
