<?php

declare(strict_types=1);

namespace App\Http\Controllers\StarOAuth;

use App\Enums\StarAccessMode;
use App\Http\Controllers\Controller;
use App\Models\Star;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/**
 * Dynamic client registration (RFC 7591) with one Star in OAuth mode.
 * laravel/mcp registers a public client that must use PKCE, refusing
 * redirect URIs that aren't HTTPS, loopback HTTP or one of the custom
 * schemes `config/mcp.php` allows; Nexus then binds the client to this
 * Star, so its tokens can only ever open this Star. Anyone may register,
 * so the route is rate limited per IP address, and a client does nothing
 * until the Star's owner approves it.
 *
 * The Star's row is locked meanwhile, so it can't switch away from OAuth
 * (revoking its clients) or be deleted before the client is bound to it.
 */
class RegisterClientController extends Controller
{
    public function __invoke(Request $request, OAuthRegisterController $registrar, string $star): JsonResponse
    {
        return DB::transaction(function () use ($request, $registrar, $star): JsonResponse {
            $star = Star::query()->where('public_id', $star)->where('access_mode', StarAccessMode::OAuth)->lockForUpdate()->firstOrFail();

            $response = $registrar($request);
            $registration = $response->getData(true);
            $clientId = is_array($registration) ? $registration['client_id'] ?? null : null;

            if ($response->getStatusCode() === 201 && is_string($clientId)) {
                $star->oauthClients()->forceCreate(['client_id' => $clientId]);
            }

            return $response;
        });
    }
}
