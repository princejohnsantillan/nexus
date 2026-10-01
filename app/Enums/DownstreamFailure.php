<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a request to a Connection's server failed.
 */
enum DownstreamFailure
{
    /** The server refused Nexus's credentials, or wants credentials it doesn't have (HTTP 401 or 403, or an invalid-token challenge). */
    case NeedsSignIn;

    /** The server did not answer within the connect or call timeout. */
    case Timeout;

    /** Nexus could not reach the server, the outbound guard refused it, or it answered with HTTP 404 or 5xx. */
    case Unreachable;

    /** The server did not answer like an MCP server Nexus can speak to. */
    case ProtocolError;

    /** The server answered a tool call with a JSON-RPC error, e.g. for a tool it no longer has. */
    case ToolError;
}
