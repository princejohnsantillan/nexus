<?php

namespace App\Mcp\Downstream;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\RedirectResponse;
use Laravel\Mcp\Client\OAuth\AuthServerMetadata;
use Laravel\Mcp\Client\OAuth\ClientRegistration;
use Laravel\Mcp\Client\OAuth\DynamicClientRegistration;
use Laravel\Mcp\Client\OAuth\OAuthClient;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The laravel/mcp OAuth client, adjusted for signing in to arbitrary
 * servers, several times over:
 *
 * - Scopes follow the MCP spec: the 401 challenge's scope, then the
 *   connection's configured scope, then the server's advertised scopes. The
 *   stock client falls back to `mcp:use`, which only Laravel servers know.
 * - Dynamic client registration identifies itself as Nexus.
 * - The provider is asked to show its account chooser, so a second Slack
 *   or Google connection doesn't silently reuse the browser's current one.
 * - Pending sign-ins are kept per connection, so two sign-ins to the same
 *   server can run side by side.
 * - Identity hints in the token response are kept for the account label.
 */
class NexusOAuthClient extends OAuthClient
{
    protected ?int $connectionId = null;

    protected ?string $accountHint = null;

    public function scopedTo(int $connectionId): static
    {
        $this->connectionId = $connectionId;

        return $this;
    }

    public function redirect(?string $returnTo = null): RedirectResponse
    {
        $response = parent::redirect($returnTo);
        $url = $response->getTargetUrl();

        if (! str_contains($url, 'prompt=')) {
            $response->setTargetUrl($url.(str_contains($url, '?') ? '&' : '?').'prompt=select_account');
        }

        return $response;
    }

    /**
     * Who the token response says the account is, if it said.
     */
    public function accountHint(): ?string
    {
        return $this->accountHint;
    }

    protected function sessionKey(): string
    {
        return 'mcp.oauth.'.sha1($this->resourceUrl.'|'.$this->connectionId);
    }

    protected function oAuthRequest(): PendingRequest
    {
        return parent::oAuthRequest()->withResponseMiddleware(function (ResponseInterface $response): ResponseInterface {
            $body = $response->getBody();
            $data = json_decode((string) $body, true);

            if ($body->isSeekable()) {
                $body->rewind();
            }

            if (is_array($data) && isset($data['access_token'])) {
                $this->accountHint = AccountIdentity::fromTokenResponse($data) ?? $this->accountHint;
            }

            return $response;
        });
    }

    protected function resolveScope(): ?string
    {
        if (filled($this->challengeScope)) {
            return $this->challengeScope;
        }

        if (filled($this->config->scope)) {
            return $this->config->scope;
        }

        try {
            $advertised = $this->discover()->scopesSupported;
        } catch (Throwable) {
            return null;
        }

        return $advertised === [] ? null : implode(' ', $advertised);
    }

    protected function register(AuthServerMetadata $metadata, string $redirectUri): ClientRegistration
    {
        if ($metadata->registrationEndpoint === null) {
            return parent::register($metadata, $redirectUri);
        }

        return (new DynamicClientRegistration)->register(
            $metadata->registrationEndpoint,
            $redirectUri,
            $this->resolveScope(),
            clientName: config('app.name'),
            applicationType: $this->applicationType($redirectUri),
            tokenEndpointAuthMethod: $this->resolveTokenAuthMethod($metadata, 'confidential'),
        );
    }
}
