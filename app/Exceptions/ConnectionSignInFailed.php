<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Connection;
use RuntimeException;

/**
 * Signing a Connection in to its server with OAuth failed.
 *
 * The message is written by Nexus and safe to show the user: it names an
 * HTTP status or a standard OAuth error code at most, never text the server
 * sent. Nothing from the server is chained as a previous exception, so
 * reporting this exception logs no downstream text.
 */
final class ConnectionSignInFailed extends RuntimeException
{
    /**
     * The error codes OAuth defines for authorization and token responses
     * (RFC 6749 §4.1.2.1 and §5.2). Only these are ever repeated to the user.
     *
     * @var list<string>
     */
    private const array STANDARD_ERRORS = [
        'access_denied', 'invalid_client', 'invalid_grant', 'invalid_request', 'invalid_scope',
        'server_error', 'temporarily_unavailable', 'unauthorized_client', 'unsupported_grant_type',
        'unsupported_response_type',
    ];

    /**
     * The Connection that was signing in, when Nexus knows which it was.
     */
    public ?Connection $connection = null;

    /**
     * Say which Connection was signing in.
     */
    public function for(Connection $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public static function because(string $message): self
    {
        return new self($message);
    }

    /**
     * The server's sign-in page sent the user back with an error instead of
     * a code, e.g. because they didn't approve Nexus.
     */
    public static function denied(mixed $error): self
    {
        if ($error === 'access_denied') {
            return new self(__('You didn\'t approve Nexus, so it isn\'t signed in.'));
        }

        return new self(self::withCode(__('The server\'s sign-in page reported an error'), $error));
    }

    /**
     * The token endpoint refused to exchange the authorization code.
     */
    public static function codeRefused(int $status, mixed $error): self
    {
        return new self(self::withCode(__('The server refused to finish the sign-in (HTTP :status)', ['status' => $status]), $error));
    }

    /**
     * The message, followed by the OAuth error code when it is a standard one.
     */
    private static function withCode(string $message, mixed $error): string
    {
        return in_array($error, self::STANDARD_ERRORS, true) ? "{$message}: {$error}." : "{$message}.";
    }
}
