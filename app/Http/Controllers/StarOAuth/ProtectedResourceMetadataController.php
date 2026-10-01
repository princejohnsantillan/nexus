<?php

declare(strict_types=1);

namespace App\Http\Controllers\StarOAuth;

use App\Enums\StarAccessMode;
use App\Http\Controllers\Controller;
use App\Models\Star;
use Illuminate\Http\JsonResponse;
use Laravel\Mcp\Server\Registrar;

/**
 * A Star's protected resource metadata (RFC 9728), which its 401 points
 * MCP clients to: the Star's endpoint is the resource, and the Star's own
 * issuer is its authorization server. Only a Star in OAuth mode has one;
 * any other Star, or none, is a 404.
 */
class ProtectedResourceMetadataController extends Controller
{
    public function __invoke(string $star): JsonResponse
    {
        $star = Star::query()->where('public_id', $star)->where('access_mode', StarAccessMode::OAuth)->firstOrFail();

        return response()->json([
            'resource' => $star->endpointUrl(),
            'authorization_servers' => [$star->oauthIssuer()],
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Nexus: '.$star->name,
        ], options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
