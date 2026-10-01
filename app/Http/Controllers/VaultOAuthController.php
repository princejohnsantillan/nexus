<?php

namespace App\Http\Controllers;

use App\Enums\VaultAuthMode;
use App\Models\Vault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Laravel\Mcp\Server\Registrar;

/**
 * Nexus as the OAuth authorization server for OAuth-mode vaults.
 *
 * Every vault presents itself as its own authorization server: its own
 * issuer and its own registration endpoint. Clients therefore register a
 * separate client per vault, and Nexus records which vault each client
 * registered for. That binding, checked on every MCP request, is what keeps
 * a token for one vault from opening another; the shared Passport token
 * endpoint can't tell vaults apart on its own.
 */
class VaultOAuthController extends Controller
{
    /**
     * RFC 9728 protected resource metadata for /mcp/{vault}.
     */
    public function protectedResource(string $vault): JsonResponse
    {
        $vault = $this->oauthVault($vault);

        return response()->json([
            'resource' => $vault->endpointUrl(),
            'authorization_servers' => [$vault->oauthIssuer()],
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => "Nexus: {$vault->name}",
        ]);
    }

    /**
     * RFC 8414 authorization server metadata for the vault's issuer.
     */
    public function authorizationServer(string $vault): JsonResponse
    {
        $vault = $this->oauthVault($vault);

        return response()->json([
            'issuer' => $vault->oauthIssuer(),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'registration_endpoint' => route('mcp.oauth.register', $vault->public_id),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
        ]);
    }

    /**
     * Dynamic client registration (RFC 7591) through laravel/mcp's
     * controller, then binding the new client to this vault.
     */
    public function register(Request $request, string $vault, OAuthRegisterController $registrar): JsonResponse
    {
        $vault = $this->oauthVault($vault);

        $response = $registrar($request);

        if ($response->getStatusCode() === 201) {
            $vault->oauthClients()->attach($response->getData(true)['client_id']);
        }

        return $response;
    }

    protected function oauthVault(string $publicId): Vault
    {
        return Vault::query()
            ->where('public_id', $publicId)
            ->where('auth_mode', VaultAuthMode::OAuth)
            ->firstOrFail();
    }
}
