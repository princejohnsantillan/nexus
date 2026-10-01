<?php

namespace App\Http\Middleware;

use App\Enums\VaultAuthMode;
use App\Mcp\Vaults\VaultContext;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request into /mcp/{vault} only with the credential the vault's
 * mode asks for: one of its nxs_ tokens, its current signed URL, or an
 * OAuth token issued to a client registered for this vault and approved by
 * the vault's owner.
 */
class AuthenticateVaultRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $vault = Vault::query()->where('public_id', $request->route('vault'))->first();

        $context = match ($vault?->auth_mode) {
            VaultAuthMode::Token => $this->withToken($request, $vault),
            VaultAuthMode::SignedUrl => $this->withSignedUrl($request, $vault),
            VaultAuthMode::OAuth => $this->withOAuth($vault),
            null => null,
        };

        if ($context === null) {
            return $this->unauthorized($vault);
        }

        app()->instance(VaultContext::class, $context);

        return $next($request);
    }

    protected function withToken(Request $request, Vault $vault): ?VaultContext
    {
        $plain = $request->bearerToken();
        $token = is_string($plain) ? VaultToken::findUsable($plain) : null;

        if ($token === null || $token->vault_id !== $vault->id) {
            return null;
        }

        $token->markUsed();

        return new VaultContext($vault, via: "Token: {$token->name}", rateLimitKey: "token:{$token->id}", token: $token);
    }

    protected function withSignedUrl(Request $request, Vault $vault): ?VaultContext
    {
        if (! $request->hasValidSignature() || (int) $request->query('v') !== $vault->signed_url_version) {
            return null;
        }

        return new VaultContext($vault, via: 'Signed URL', rateLimitKey: "signed-url:{$vault->id}");
    }

    /**
     * The guard has already checked the token's signature, expiry and
     * revocation. What's left is the binding: the token's user owns this
     * vault, and its client was registered for this vault.
     */
    protected function withOAuth(Vault $vault): ?VaultContext
    {
        $user = Auth::guard('api')->user();
        $token = $user instanceof User ? $user->token() : null;

        if (! $token instanceof AccessToken || $token->cant(Registrar::OAUTH_SCOPE) || $user->getKey() !== $vault->user_id) {
            return null;
        }

        $client = $vault->oauthClients()->whereKey($token->oauth_client_id)->first();

        if ($client === null || $client->revoked) {
            return null;
        }

        return new VaultContext($vault, via: "OAuth: {$client->name}", rateLimitKey: "oauth:{$token->oauth_access_token_id}");
    }

    /**
     * An unknown vault and a wrong credential get the same answer. OAuth
     * vaults also say where their metadata is, so clients can start sign-in.
     */
    protected function unauthorized(?Vault $vault): Response
    {
        $challenge = $vault?->auth_mode === VaultAuthMode::OAuth
            ? sprintf('Bearer resource_metadata="%s", scope="%s"', route('mcp.oauth.protected-resource', $vault->public_id), Registrar::OAUTH_SCOPE)
            : 'Bearer realm="nexus"';

        return response()->json([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32001,
                'message' => 'Unauthorized: this vault needs its signed URL, one of its tokens, or OAuth sign-in, depending on how it is set up in Nexus.',
            ],
        ], 401, ['WWW-Authenticate' => $challenge]);
    }
}
