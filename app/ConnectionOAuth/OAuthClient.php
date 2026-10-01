<?php

declare(strict_types=1);

namespace App\ConnectionOAuth;

use App\Concerns\KeepsSecretsInMemory;
use App\Enums\OAuthClientSource;
use SensitiveParameter;

/**
 * The OAuth client Nexus signs in to a Connection's server as.
 */
final readonly class OAuthClient
{
    use KeepsSecretsInMemory;

    /**
     * @param  string  $authMethod  How the client authenticates at the token endpoint: `client_secret_post`, `client_secret_basic` or `none`.
     */
    public function __construct(
        public string $id,
        #[SensitiveParameter] public ?string $secret,
        public OAuthClientSource $source,
        public string $authMethod,
    ) {}

    /**
     * The client's credentials for a token request: form fields, and the
     * pair for HTTP Basic when it authenticates that way.
     *
     * @return array{0: array<string, string>, 1: array{0: string, 1: string}|null}
     */
    public function credentials(): array
    {
        return match ($this->authMethod) {
            'client_secret_basic' => [[], [$this->id, $this->secret ?? '']],
            'client_secret_post' => [['client_id' => $this->id, 'client_secret' => $this->secret ?? ''], null],
            default => [['client_id' => $this->id], null],
        };
    }
}
