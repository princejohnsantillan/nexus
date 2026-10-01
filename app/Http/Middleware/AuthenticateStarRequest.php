<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Downstream\RawJson;
use App\Enums\StarAccessMode;
use App\Mcp\StarCaller;
use App\Models\Star;
use App\Models\StarToken;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request into a Star's MCP endpoint only with a credential for that
 * Star, of the kind its access mode asks for: in token mode, one of its own
 * tokens as `Authorization: Bearer nxs_…`.
 *
 * The endpoint has no session, so the Star is looked up here by its public
 * id rather than among the signed-in user's records. An unknown Star and a
 * wrong credential get the same 401, which says how to authenticate.
 */
class AuthenticateStarRequest implements AuthenticatesRequests
{
    /**
     * The JSON-RPC error code of the 401's body.
     */
    private const int UNAUTHORIZED = -32001;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $publicId = $request->route('star');
        $star = is_string($publicId) ? Star::query()->where('public_id', $publicId)->first() : null;

        $caller = match ($star?->access_mode) {
            StarAccessMode::Token => $this->withToken($request, $star),
            null => null,
        };

        if (! $caller instanceof StarCaller) {
            return $this->unauthorized($request);
        }

        $caller->attachTo($request);

        return $next($request);
    }

    private function withToken(Request $request, Star $star): ?StarCaller
    {
        $plainToken = $request->bearerToken();
        $token = is_string($plainToken) ? StarToken::findByPlainToken($plainToken) : null;

        if (! $token instanceof StarToken || $token->star_id !== $star->id) {
            return null;
        }

        $token->markUsed();

        return StarCaller::withToken($star, $token);
    }

    /**
     * A Bearer challenge, with `invalid_token` when the request carried a
     * token that isn't one of the Star's, and a JSON-RPC error in the body.
     */
    private function unauthorized(Request $request): JsonResponse
    {
        $id = json_decode(RawJson::member($request->getContent(), 'id') ?? 'null');

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => is_int($id) || is_string($id) ? $id : null,
            'error' => [
                'code' => self::UNAUTHORIZED,
                'message' => __('Unauthorized: send one of this Star\'s tokens as "Authorization: Bearer nxs_…". Its owner creates them on the Star\'s Access page in Nexus.'),
            ],
        ], 401, [
            'WWW-Authenticate' => $request->bearerToken() === null ? 'Bearer realm="nexus"' : 'Bearer realm="nexus", error="invalid_token"',
        ], JSON_UNESCAPED_UNICODE);
    }
}
