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
 * Star, of the kind its current access mode asks for: in token mode, one of
 * its own tokens as `Authorization: Bearer nxs_…`; in signed-URL mode, its
 * signed URL at its current version. A credential of another mode is
 * refused, so switching modes never leaves the old one working.
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
            StarAccessMode::SignedUrl => $this->withSignedUrl($request, $star),
            null => null,
        };

        if (! $caller instanceof StarCaller) {
            return $this->unauthorized($request, $star);
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
     * The Star's signed URL, at its current version: a URL from before the
     * last rotation is signed correctly but carries an older version.
     */
    private function withSignedUrl(Request $request, Star $star): ?StarCaller
    {
        if (! $request->hasValidRelativeSignature() || $request->query('v') !== (string) $star->signed_url_version) {
            return null;
        }

        return StarCaller::withSignedUrl($star);
    }

    /**
     * A Bearer challenge, with `invalid_token` when the request carried a
     * token that isn't one of the Star's, and a JSON-RPC error in the body
     * saying how to authenticate in the Star's access mode. An unknown Star
     * is answered as a Star in token mode, the default.
     */
    private function unauthorized(Request $request, ?Star $star): JsonResponse
    {
        $id = json_decode(RawJson::member($request->getContent(), 'id') ?? 'null');

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => is_int($id) || is_string($id) ? $id : null,
            'error' => [
                'code' => self::UNAUTHORIZED,
                'message' => match ($star?->access_mode) {
                    StarAccessMode::SignedUrl => __('Unauthorized: connect with this Star\'s signed URL. Its owner copies it from the Star\'s Access page in Nexus.'),
                    StarAccessMode::Token, null => __('Unauthorized: send one of this Star\'s tokens as "Authorization: Bearer nxs_…". Its owner creates them on the Star\'s Access page in Nexus.'),
                },
            ],
        ], 401, [
            'WWW-Authenticate' => $request->bearerToken() === null ? 'Bearer realm="nexus"' : 'Bearer realm="nexus", error="invalid_token"',
        ], JSON_UNESCAPED_UNICODE);
    }
}
