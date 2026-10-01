<?php

namespace App\Connectors;

use Filament\Support\Icons\Heroicon;

/**
 * A ready-made connection to an official MCP server: everything except the
 * user's own sign-in.
 */
final class Connector
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $summary,
        public readonly string $url,
        public readonly Heroicon $icon,
        public readonly string $docsUrl,
        public readonly ClientRegistration $registration = ClientRegistration::Automatic,
        public readonly ?string $scope = null,
        public readonly ?string $appConsoleUrl = null,
        public readonly ?string $appInstructions = null,
        public readonly ?array $appManifest = null,
        public readonly bool $preview = false,
        public readonly bool $requiresDeploymentApp = false,
    ) {}

    /**
     * Whether users can connect it at all. Some connectors can only use an
     * app this deployment registered (e.g. Gmail, whose server is in
     * Google's Developer Preview and needs an enrolled Cloud project).
     */
    public function isAvailable(): bool
    {
        return ! $this->requiresDeploymentApp || $this->deploymentClient() !== null;
    }

    /**
     * The provider's app manifest with Nexus's callback filled in, for
     * providers that can create an app from one (Slack).
     */
    public function appManifestJson(string $callbackUrl): ?string
    {
        if ($this->appManifest === null) {
            return null;
        }

        $manifest = $this->appManifest;
        data_set($manifest, 'oauth_config.redirect_urls', [$callbackUrl]);

        return (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
     * Whether the user has to bring their own OAuth app to sign in.
     */
    public function needsUserClient(): bool
    {
        return $this->registration === ClientRegistration::PreRegistered
            && ! $this->requiresDeploymentApp
            && $this->deploymentClient() === null;
    }
}
