<?php

namespace App\Mcp\Downstream;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Uri;
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
 *   Google is also asked for offline access, without which it issues no
 *   refresh token and the user would have to sign in again every hour.
 * - When the server's metadata names its resource differently from its URL
 *   (Slack), the authorization request uses the server's name for it.
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

        $uri = Uri::of($response->getTargetUrl());
        $google = $uri->host() === 'accounts.google.com';

        $uri = $uri->withQuery(array_filter([
            'prompt' => $google ? 'consent select_account' : 'select_account',
            'access_type' => $google ? 'offline' : null,
            'resource' => $this->advertisedResource(),
        ]));

        return $response->setTargetUrl((string) $uri);
    }

    /**
     * How the server's metadata names the resource, when that differs from
     * the server URL. Worth storing: later token requests should use it.
     */
    public function advertisedResource(): ?string
    {
        return $this->discovery instanceof NexusAuthServerDiscovery ? $this->discovery->advertisedResource : null;
    }

    /**
     * Who the token response says the account is, if it said.
     */
    public function accountHint(): ?string
    {
        return $this->accountHint;
    }

    /**
     * Keyed by connection rather than resource URL: the URL used to start a
     * sign-in can differ from the one used to finish it (see
     * advertisedResource()).
     */
    protected function sessionKey(): string
    {
        return $this->connectionId === null
            ? parent::sessionKey()
            : "mcp.oauth.connection.{$this->connectionId}";
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
