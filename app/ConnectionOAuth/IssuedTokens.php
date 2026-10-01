<?php

declare(strict_types=1);

namespace App\ConnectionOAuth;

use App\Concerns\KeepsSecretsInMemory;
use SensitiveParameter;

/**
 * The tokens a token endpoint issued.
 */
final readonly class IssuedTokens
{
    use KeepsSecretsInMemory;

    /**
     * An access token Nexus can send as `Authorization: Bearer …`: visible
     * ASCII only, which RFC 6750's token characters all are. Anything else,
     * such as a line break, can't go in a header.
     */
    private const string ACCESS_TOKEN_PATTERN = '/^[\x21-\x7E]+\z/';

    /**
     * @param  string|null  $refreshToken  Null when the server issued none, or (on renewal) kept the one Nexus has.
     * @param  int|null  $expiresAt  When the access token expires, as a Unix timestamp; null when the server didn't say.
     * @param  string|null  $accountIdentity  The account the response says was signed in to (see DetectAccountIdentity), or null when it doesn't say.
     */
    public function __construct(
        #[SensitiveParameter] public string $accessToken,
        #[SensitiveParameter] public ?string $refreshToken,
        public ?int $expiresAt,
        public ?string $accountIdentity = null,
    ) {}

    /**
     * The tokens in a token response, or null when it holds no access token
     * Nexus can send.
     *
     * @param  array<string, mixed>  $response
     * @param  string|null  $accountIdentity  The account the response names.
     */
    public static function fromResponse(#[SensitiveParameter] array $response, ?string $accountIdentity = null): ?self
    {
        $accessToken = $response['access_token'] ?? null;
        $refreshToken = $response['refresh_token'] ?? null;
        $expiresIn = $response['expires_in'] ?? null;

        if (! is_string($accessToken) || preg_match(self::ACCESS_TOKEN_PATTERN, $accessToken) !== 1) {
            return null;
        }

        return new self(
            accessToken: $accessToken,
            refreshToken: is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            expiresAt: is_int($expiresIn) || (is_string($expiresIn) && ctype_digit($expiresIn)) ? now()->getTimestamp() + (int) $expiresIn : null,
            accountIdentity: $accountIdentity,
        );
    }
}
