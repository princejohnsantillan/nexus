<?php

declare(strict_types=1);

namespace App\Http\Controllers\StarOAuth;

use App\Enums\StarAccessMode;
use App\Http\Controllers\Controller;
use App\Models\Star;
use Illuminate\Http\JsonResponse;
use Laravel\Mcp\Server\Registrar;

/**
 * The authorization server metadata (RFC 8414) of a Star's own issuer,
 * also served as its OpenID configuration for clients that look there.
 * Every Star shares Passport's authorization and token endpoints, but has
 * its own registration endpoint, which binds each client to the Star.
 * Clients register as public clients and must use PKCE with S256. Only a
 * Star in OAuth mode has an issuer; any other Star, or none, is a 404.
 */
class AuthorizationServerMetadataController extends Controller
{
    public function __invoke(string $star): JsonResponse
    {
        $star = Star::query()->where('public_id', $star)->where('access_mode', StarAccessMode::OAuth)->firstOrFail();

        return response()->json([
            'issuer' => $star->oauthIssuer(),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'registration_endpoint' => route('mcp.oauth.register', $star),
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
        ], options: JSON_UNESCAPED_SLASHES);
    }
}
