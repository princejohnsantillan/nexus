<?php

declare(strict_types=1);

namespace App\Connectors;

use App\Enums\ClientRegistration;
use App\Enums\SignInMethod;
use Illuminate\Support\HtmlString;

/**
 * A gallery entry for a service's official remote MCP server: everything
 * about connecting to it except the user's own sign-in. Each is defined by
 * `resources/connectors/{key}.json`, with its official logo at
 * `resources/connectors/logos/{key}.svg`.
 */
final readonly class Connector
{
    /**
     * @param  string  $logoSvg  The official logo, as validated SVG markup.
     * @param  list<string>  $scopes  The OAuth scopes to request; none lets the server's challenge decide.
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $summary,
        public string $url,
        public string $docsUrl,
        public ClientRegistration $registration,
        public string $logoSvg,
        public array $scopes = [],
        public bool $preview = false,
        public bool $requiresDeploymentApp = false,
        public ?ConnectorApp $app = null,
        public ?ConnectorToken $token = null,
    ) {}

    /**
     * Every way the connector's server lets users sign in: OAuth, and a token
     * of their own when the definition says how to make one.
     *
     * @return list<SignInMethod>
     */
    public function methods(): array
    {
        return $this->token instanceof ConnectorToken ? [SignInMethod::Token, SignInMethod::OAuth] : [SignInMethod::OAuth];
    }

    /**
     * The methods a user can sign in with on this Nexus.
     *
     * @return list<SignInMethod>
     */
    public function availableMethods(): array
    {
        return array_values(array_filter(
            $this->methods(),
            fn (SignInMethod $method): bool => $this->whyUnavailable($method) === null,
        ));
    }

    /**
     * Whether a user can connect the service on this Nexus at all.
     */
    public function isAvailable(): bool
    {
        return $this->availableMethods() !== [];
    }

    /**
     * Why a user can't sign in with a method on this Nexus, or null when
     * they can.
     *
     * Nexus can't sign in to a server with OAuth until Connection OAuth is
     * built, so OAuth is never available for now.
     */
    public function whyUnavailable(SignInMethod $method): ?string
    {
        if ($method === SignInMethod::Token) {
            if (! $this->token instanceof ConnectorToken) {
                return __(':name doesn\'t accept tokens.', ['name' => $this->name]);
            }

            return null;
        }

        if ($this->requiresDeploymentApp && $this->deploymentApp() === null) {
            return __('This Nexus has no :name OAuth app yet. Its operator needs to register one and set :client_id and :client_secret.', [
                'name' => $this->name,
                'client_id' => $this->environmentVariable('CLIENT_ID'),
                'client_secret' => $this->environmentVariable('CLIENT_SECRET'),
            ]);
        }

        return __('Nexus can\'t sign in with OAuth yet.');
    }

    /**
     * Why a user can't connect the service on this Nexus, or null when they can.
     */
    public function unavailableReason(): ?string
    {
        if ($this->isAvailable()) {
            return null;
        }

        return __(':name only supports signing in with OAuth. :reason', [
            'name' => $this->name,
            'reason' => $this->whyUnavailable(SignInMethod::OAuth),
        ]);
    }

    /**
     * The method to preselect: OAuth when it needs nothing from the user,
     * otherwise their own token when the service takes one, or null when the
     * connector isn't available here.
     */
    public function suggestedMethod(): ?SignInMethod
    {
        $available = $this->availableMethods();

        if (in_array(SignInMethod::OAuth, $available, true) && ! $this->needsUserApp()) {
            return SignInMethod::OAuth;
        }

        return in_array(SignInMethod::Token, $available, true) ? SignInMethod::Token : ($available[0] ?? null);
    }

    /**
     * Whether a user signing in with OAuth has to register their own OAuth
     * app: the server only accepts registered apps, and this deployment
     * hasn't configured one.
     */
    public function needsUserApp(): bool
    {
        return $this->registration === ClientRegistration::PreRegistered
            && ! $this->requiresDeploymentApp
            && $this->deploymentApp() === null;
    }

    /**
     * The OAuth app this deployment registered for the connector, if any,
     * from `NEXUS_{KEY}_CLIENT_ID` and `NEXUS_{KEY}_CLIENT_SECRET`. A
     * connector's JSON file never holds credentials.
     *
     * @return array{client_id: string, client_secret: string|null}|null
     */
    public function deploymentApp(): ?array
    {
        $clientId = config("nexus.connectors.{$this->key}.client_id");
        $clientSecret = config("nexus.connectors.{$this->key}.client_secret");

        if (! is_string($clientId) || $clientId === '') {
            return null;
        }

        return [
            'client_id' => $clientId,
            'client_secret' => is_string($clientSecret) && $clientSecret !== '' ? $clientSecret : null,
        ];
    }

    /**
     * The name of one of the connector's environment variables, such as
     * `NEXUS_GITHUB_CLIENT_ID`: dashes in the key become underscores.
     */
    public function environmentVariable(string $suffix): string
    {
        return 'NEXUS_'.strtoupper(str_replace('-', '_', $this->key)).'_'.$suffix;
    }

    /**
     * The official logo as inline SVG, hidden from assistive technology
     * (the service's name is always next to it), with the given classes.
     */
    public function logo(string $class = ''): HtmlString
    {
        $attributes = ' aria-hidden="true" focusable="false"'.($class === '' ? '' : ' class="'.e($class).'"');

        return new HtmlString('<svg'.$attributes.substr($this->logoSvg, strlen('<svg')));
    }
}
