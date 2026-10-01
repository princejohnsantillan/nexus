<?php

namespace App\Connectors;

use Filament\Support\Icons\Heroicon;

/**
 * A ready-made connection to an official MCP server: everything except the
 * user's own sign-in. Defined by a JSON file in resources/connectors.
 *
 * Its icon is the service's own logo when resources/connectors/logos has
 * one ("connector-{key}"), otherwise the Heroicon its file names.
 */
final class Connector
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>|null  $appManifest
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $summary,
        public readonly string $url,
        public readonly Heroicon|string $icon,
        public readonly string $docsUrl,
        public readonly ClientRegistration $registration = ClientRegistration::Automatic,
        public readonly array $scopes = [],
        public readonly ?string $appConsoleUrl = null,
        public readonly ?string $appInstructions = null,
        public readonly ?array $appManifest = null,
        public readonly bool $preview = false,
        public readonly bool $requiresDeploymentApp = false,
        public readonly ?TokenAuth $token = null,
    ) {}

    /**
     * Whether users can connect with their own token instead of OAuth.
     */
    public function supportsToken(): bool
    {
        return $this->token !== null;
    }

    /**
     * The scope to request, space-separated, or null to let the server's
     * challenge decide.
     */
    public function scope(): ?string
    {
        return $this->scopes === [] ? null : implode(' ', $this->scopes);
    }

    /**
     * The OAuth app this deployment registered for the connector, if any,
     * from NEXUS_{KEY}_CLIENT_ID and NEXUS_{KEY}_CLIENT_SECRET.
     *
     * @return array{client_id: string, client_secret: string|null}|null
     */
    public function deploymentClient(): ?array
    {
        $clientId = config("nexus.connectors.{$this->key}.client_id");

        return filled($clientId)
            ? ['client_id' => (string) $clientId, 'client_secret' => config("nexus.connectors.{$this->key}.client_secret") ?: null]
            : null;
    }

    /**
     * Whether users can connect it at all. Some connectors can only use an
     * app this deployment registered (e.g. Gmail, whose server is in
     * Google's Developer Preview and needs an enrolled Cloud project).
     */
    public function isAvailable(): bool
    {
        return $this->supportsToken() || $this->supportsOAuth();
    }

    /**
     * Whether OAuth sign-in can work here: always, unless the connector only
     * works with an app this deployment registered and none is configured.
     */
    public function supportsOAuth(): bool
    {
        return ! $this->requiresDeploymentApp || $this->deploymentClient() !== null;
    }

    /**
     * The sign-in method to suggest: OAuth when it needs nothing from the
     * user, otherwise their own token if the connector accepts one.
     */
    public function suggestedMethod(): string
    {
        if (! $this->supportsToken()) {
            return 'oauth';
        }

        return $this->supportsOAuth() && ! $this->needsUserClient() ? 'oauth' : 'token';
    }

    /**
     * Whether the user has to bring their own OAuth app to sign in.
     */
    public function needsUserClient(): bool
    {
        return $this->registration === ClientRegistration::PreRegistered
            && ! $this->requiresDeploymentApp
            && $this->deploymentClient() === null;
    }

    /**
     * The provider's app manifest with its placeholders filled in:
     * "{{callback_url}}" becomes Nexus's OAuth callback and "{{scopes}}" the
     * connector's scopes.
     */
    public function appManifestJson(string $callbackUrl): ?string
    {
        if ($this->appManifest === null) {
            return null;
        }

        $fill = function (mixed $value) use (&$fill, $callbackUrl): mixed {
            return match (true) {
                is_array($value) => array_map($fill, $value),
                $value === '{{callback_url}}' => $callbackUrl,
                $value === '{{scopes}}' => $this->scopes,
                default => $value,
            };
        };

        return (string) json_encode($fill($this->appManifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
