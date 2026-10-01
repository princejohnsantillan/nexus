<?php

namespace App\Http\Middleware;

use App\Mcp\Vaults\VaultContext;
use App\Models\Vault;
use App\Models\VaultToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request into /mcp/{vault} only with a live token for that vault.
 *
 * An unknown vault and a wrong token get the same answer, so the endpoint
 * doesn't reveal which vault ids exist.
 */
class AuthenticateVaultToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = is_string($plain) ? VaultToken::findUsable($plain) : null;
        $vault = $token === null ? null : Vault::query()->whereKey($token->vault_id)->where('public_id', $request->route('vault'))->first();

        if ($vault === null) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: send a valid Nexus token for this vault as "Authorization: Bearer nxs_…".',
                ],
            ], 401);
        }

        $token->markUsed();

        app()->instance(VaultContext::class, new VaultContext($vault, $token));

        return $next($request);
    }
}
