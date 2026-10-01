<?php

namespace App\Mcp\Downstream;

use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use Laravel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Laravel\Mcp\Client\Exceptions\OAuthException;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Schema\Implementation;
use Throwable;

/**
 * Opens authenticated sessions to a connection's MCP server.
 */
class DownstreamClients
{
    public function __construct(protected OAuthTokens $tokens) {}

    /**
     * @throws ConnectionNeedsAuth when the server rejects the credentials.
     */
    public function open(Connection $connection): DownstreamSession
    {
        try {
            return $this->attempt($connection, authenticate: true);
        } catch (AuthorizationRequiredException $exception) {
            return $this->reopenAfterRejection($connection, $exception);
        }
    }

    /**
     * Open a session without credentials, to see whether the server wants
     * any and, if so, read its OAuth challenge.
     */
    public function probe(Connection $connection): DownstreamSession
    {
        return $this->attempt($connection, authenticate: false);
    }

    /**
     * The server said no to credentials we believed were valid. For OAuth,
     * force one token refresh and try again; otherwise the user must fix it.
     *
     * @throws ConnectionNeedsAuth
     */
    public function reopenAfterRejection(Connection $connection, AuthorizationRequiredException $exception): DownstreamSession
    {
        if ($connection->auth_type === ConnectionAuthType::OAuth) {
            $this->tokens->refresh($connection, staleToken: $connection->secret('access_token'));

            try {
                return $this->attempt($connection, authenticate: true);
            } catch (AuthorizationRequiredException $exception) {
                //
            }
        }

        $reason = $connection->auth_type === ConnectionAuthType::Header
            ? 'The server rejected the configured header. Check the API key or token.'
            : $exception->getMessage();

        $connection->markStatus(ConnectionStatus::NeedsAuth, $reason);

        throw new ConnectionNeedsAuth($connection, $reason);
    }

    protected function attempt(Connection $connection, bool $authenticate): DownstreamSession
    {
        $transport = new RecordingHttpTransport($connection->url);

        $client = new NexusWebClient($transport, new Implementation(
            name: 'nexus',
            version: '1.0.0',
            title: config('app.name'),
            websiteUrl: config('app.url'),
        ));

        $client->withTimeout(config('nexus.downstream.timeout'));

        if ($authenticate) {
            $this->authenticate($client, $connection);
        }

        $this->connect($client, $connection);

        return new DownstreamSession($client, $transport);
    }

    protected function authenticate(NexusWebClient $client, Connection $connection): void
    {
        match ($connection->auth_type) {
            ConnectionAuthType::None => null,
            ConnectionAuthType::Header => $client->withHeaders([
                (string) $connection->setting('header_name', 'Authorization') => (string) $connection->secret('header_value', ''),
            ]),
            ConnectionAuthType::OAuth => $client->withToken($this->tokens->accessToken($connection)),
        };
    }

    /**
     * Connect using the protocol version that worked last time, which skips
     * a failing probe against older servers. If the server has changed,
     * negotiate from scratch and remember the new version.
     */
    protected function connect(NexusWebClient $client, Connection $connection): void
    {
        $remembered = ProtocolVersion::tryFrom((string) $connection->protocol_version);

        if ($remembered !== null && in_array($remembered->value, ProtocolVersion::clientSupported(), true)) {
            try {
                $client->withProtocolVersion($remembered)->connect();

                return;
            } catch (OAuthException $exception) {
                throw $exception;
            } catch (Throwable) {
                $client->withProtocolVersion(null);
            }
        }

        $client->connect();

        $negotiated = $client->protocolVersion()->value;

        if ($negotiated !== $connection->protocol_version) {
            $connection->forceFill(['protocol_version' => $negotiated])->saveQuietly();
        }
    }
}
