<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\DownstreamFailure;
use Laravel\Mcp\Client\OAuth\WwwAuthenticateChallenge;
use RuntimeException;

/**
 * A request to a Connection's MCP server failed.
 *
 * The failure says why, and the message is written by Nexus and safe to show
 * the user: it names an HTTP status or a JSON-RPC error code at most, never
 * text the server sent. Nothing from the server is chained as a previous
 * exception, so reporting this exception logs no downstream text.
 */
final class DownstreamRequestFailed extends RuntimeException
{
    private function __construct(
        public readonly DownstreamFailure $failure,
        string $message,
        public readonly ?WwwAuthenticateChallenge $challenge = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The server answered HTTP 401 or 403, or rejected the token Nexus sent.
     *
     * @param  bool  $sentCredentials  Whether Nexus sent credentials it refused, rather than none.
     * @param  WwwAuthenticateChallenge  $challenge  How the server's WWW-Authenticate header asks Nexus to sign in.
     */
    public static function needsSignIn(int $status, bool $sentCredentials, WwwAuthenticateChallenge $challenge): self
    {
        return new self(
            DownstreamFailure::NeedsSignIn,
            $sentCredentials
                ? __('The server refused the credentials Nexus sent (HTTP :status).', ['status' => $status])
                : __('The server requires sign-in (HTTP :status).', ['status' => $status]),
            $challenge,
        );
    }

    public static function timedOut(): self
    {
        return new self(DownstreamFailure::Timeout, __('The server took too long to answer, so Nexus stopped waiting.'));
    }

    public static function unreachable(): self
    {
        return new self(DownstreamFailure::Unreachable, __('Nexus could not connect to the server.'));
    }

    /**
     * The outbound guard refused the request; its message is safe to show.
     */
    public static function blocked(OutboundRequestBlocked $blocked): self
    {
        return new self(DownstreamFailure::Unreachable, $blocked->getMessage());
    }

    /**
     * The server answered HTTP 404 or a server error.
     */
    public static function httpError(int $status): self
    {
        return new self(DownstreamFailure::Unreachable, __('The server answered with HTTP :status.', ['status' => $status]));
    }

    /**
     * The server refused the request with another 4xx status.
     */
    public static function rejected(int $status): self
    {
        return new self(DownstreamFailure::ProtocolError, __('The server rejected Nexus\'s request with HTTP :status.', ['status' => $status]));
    }

    /**
     * The server answered a request other than a tool call with a JSON-RPC error.
     */
    public static function jsonRpcError(int $code): self
    {
        return new self(DownstreamFailure::ProtocolError, __('The server answered with a JSON-RPC error (code :code).', ['code' => $code]));
    }

    public static function unsupportedProtocolVersion(): self
    {
        return new self(DownstreamFailure::ProtocolError, __('The server speaks an MCP protocol version Nexus does not support.'));
    }

    public static function protocolError(): self
    {
        return new self(DownstreamFailure::ProtocolError, __('The server did not answer like an MCP server.'));
    }

    /**
     * The server answered a tool call with a JSON-RPC error, rather than a tool result.
     */
    public static function toolError(int $code): self
    {
        return new self(DownstreamFailure::ToolError, __('The server refused the tool call with a JSON-RPC error (code :code).', ['code' => $code]));
    }
}
