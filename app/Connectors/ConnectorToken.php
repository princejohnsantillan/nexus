<?php

declare(strict_types=1);

namespace App\Connectors;

use SensitiveParameter;

/**
 * Signing in to a connector's server with a token the user creates
 * themselves, such as a GitHub personal access token, sent as a header. No
 * OAuth app is involved, so the server sees the user's own credentials.
 */
final readonly class ConnectorToken
{
    public function __construct(
        public string $consoleUrl,
        public string $instructions,
        public string $headerName = 'Authorization',
        public string $valuePrefix = 'Bearer ',
    ) {}

    /**
     * The header value for a pasted token: the token after the value prefix,
     * which the user may have pasted with it, in any case.
     */
    public function headerValue(#[SensitiveParameter] string $token): string
    {
        $token = trim($token);
        $prefix = rtrim($this->valuePrefix);

        if ($prefix !== '') {
            $separator = $prefix === $this->valuePrefix ? '' : '\s+';
            $token = preg_replace('/^'.preg_quote($prefix, '/').$separator.'/i', '', $token, 1) ?? $token;
        }

        return $this->valuePrefix.$token;
    }
}
