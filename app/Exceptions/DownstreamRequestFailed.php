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
    /**
     * @param  int|null  $jsonRpcCode  The JSON-RPC error code the server answered with, when it answered with one.
     */
    private function __construct(
        public readonly DownstreamFailure $failure,
        string $message,
        public readonly ?WwwAuthenticateChallenge $challenge = null,
        public readonly ?int $jsonRpcCode = null,
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

    /**
     * An OAuth Connection has no access token: the user hasn't signed in yet,
     * or their sign-in ended.
     */
    public static function notSignedIn(): self
    {
        return new self(DownstreamFailure::NeedsSignIn, __('Nexus isn\'t signed in to this server. Reconnect to sign in.'));
    }

    /**
     * An OAuth Connection's access token expired, and the server refused to
     * renew it (or never gave Nexus a way to).
     */
    public static function signInExpired(): self
    {
        return new self(DownstreamFailure::NeedsSignIn, __('The sign-in expired and the server didn\'t renew it. Reconnect to sign in again.'));
    }

    /**
     * Renewing an OAuth Connection's expired access token failed for a reason
     * that may pass, such as the server's sign-in service not answering. The
     * sign-in itself is kept.
     */
    public static function renewalFailed(): self
    {
        return new self(DownstreamFailure::Unreachable, __('Nexus couldn\'t renew the sign-in: the server\'s sign-in service didn\'t answer properly. Try again in a moment.'));
    }

    /**
     * Another request was renewing the Connection's access token, and didn't
     * finish in time.
     */
    public static function renewalBusy(): self
    {
        return new self(DownstreamFailure::Timeout, __('Nexus is still renewing the sign-in in another request. Try again in a moment.'));
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
     * The HTTP client couldn't build the request: the Connection's URL, or
     * a credential it sends in a header, holds characters a request can't
     * carry.
     */
    public static function unsendable(): self
    {
        return new self(DownstreamFailure::NeedsSignIn, __('Nexus can\'t send a request with this Connection\'s URL or credentials: they hold characters a request can\'t carry. Update them, or sign in again.'));
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
        return new self(DownstreamFailure::ProtocolError, __('The server answered with a JSON-RPC error (code :code).', ['code' => $code]), jsonRpcCode: $code);
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
        return new self(DownstreamFailure::ToolError, __('The server refused the tool call with a JSON-RPC error (code :code).', ['code' => $code]), jsonRpcCode: $code);
    }
}
