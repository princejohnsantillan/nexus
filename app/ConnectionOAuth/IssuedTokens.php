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
     * @param  string|null  $refreshToken  Null when the server issued none, or (on renewal) kept the one Nexus has.
     * @param  int|null  $expiresAt  When the access token expires, as a Unix timestamp; null when the server didn't say.
     */
    public function __construct(
        #[SensitiveParameter] public string $accessToken,
        #[SensitiveParameter] public ?string $refreshToken,
        public ?int $expiresAt,
    ) {}

    /**
     * The tokens in a token response, or null when it holds no access token.
     *
     * @param  array<string, mixed>  $response
     */
    public static function fromResponse(#[SensitiveParameter] array $response): ?self
    {
        $accessToken = $response['access_token'] ?? null;
        $refreshToken = $response['refresh_token'] ?? null;
        $expiresIn = $response['expires_in'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        return new self(
            accessToken: $accessToken,
            refreshToken: is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            expiresAt: is_int($expiresIn) || (is_string($expiresIn) && ctype_digit($expiresIn)) ? now()->getTimestamp() + (int) $expiresIn : null,
        );
    }
}
