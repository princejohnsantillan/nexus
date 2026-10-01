<?php

namespace App\Connectors;

/**
 * Signing in with a token the user creates themselves (e.g. a GitHub
 * personal access token), sent as a header. No OAuth app is involved, so
 * the server sees the user's own credentials.
 */
final class TokenAuth
{
    public function __construct(
        public readonly string $consoleUrl,
        public readonly string $instructions,
        public readonly string $header = 'Authorization',
        public readonly string $prefix = 'Bearer ',
    ) {}

    /**
     * The header value for a pasted token, adding the prefix unless the
     * user already included it.
     */
    public function headerValue(string $token): string
    {
        $token = trim($token);

        return $this->prefix !== '' && str_starts_with($token, $this->prefix) ? $token : $this->prefix.$token;
    }
}
