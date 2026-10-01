<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Downstream\RawJson;
use App\Enums\StarAccessMode;
use App\Mcp\StarCaller;
use App\Models\Star;
use App\Models\StarOAuthClient;
use App\Models\StarToken;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request into a Star's MCP endpoint only with a credential for that
 * Star, of the kind its current access mode asks for: in token mode, one of
 * its own tokens as `Authorization: Bearer nxs_…`; in signed-URL mode, its
 * signed URL at its current version; in OAuth mode, an access token Nexus
 * issued to one of its connected apps. A credential of another mode is
 * refused, so switching modes never leaves the old one working.
 *
 * The endpoint has no session, so the Star is looked up here by its public
 * id rather than among the signed-in user's records. An unknown Star and a
 * wrong credential get the same 401, which says how to authenticate; in
 * OAuth mode it also tells clients where to start signing in.
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
        $sentToken = $request->bearerToken() !== null;

        $caller = match ($star?->access_mode) {
            StarAccessMode::Token => $this->withToken($request, $star),
            StarAccessMode::SignedUrl => $this->withSignedUrl($request, $star),
            StarAccessMode::OAuth => $this->withOAuth($star),
            null => null,
        };

        if (! $caller instanceof StarCaller) {
            return $this->unauthorized($request, $star, $sentToken);
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
     * An access token Nexus issued with the `mcp:use` scope. Passport's
     * guard checks its signature, expiry and revocation, and that its
     * client isn't revoked. What's left is the binding: the token was
     * issued to the Star's owner, for a client that registered with this
     * Star and that the owner approved. A token for another Star, even
     * one of the same user's, is refused.
     */
    private function withOAuth(Star $star): ?StarCaller
    {
        $user = Auth::guard('api')->user();
        $token = $user instanceof User ? $user->token() : null;

        if (! $user instanceof User || ! $token instanceof AccessToken || $token->cant(Registrar::OAUTH_SCOPE) || $user->id !== $star->user_id) {
            return null;
        }

        $app = $star->connectedApps()->where('client_id', $token->oauth_client_id)->with('client')->first();

        if (! $app instanceof StarOAuthClient) {
            return null;
        }

        $app->markUsed();

        return StarCaller::withOAuthClient($star, $app);
    }

    /**
     * A Bearer challenge and a JSON-RPC error in the body saying how to
     * authenticate in the Star's access mode. An unknown Star is answered
     * as a Star in token mode, the default.
     */
    private function unauthorized(Request $request, ?Star $star, bool $sentToken): JsonResponse
    {
        $id = json_decode(RawJson::member($request->getContent(), 'id') ?? 'null');

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => is_int($id) || is_string($id) ? $id : null,
            'error' => [
                'code' => self::UNAUTHORIZED,
                'message' => match ($star?->access_mode) {
                    StarAccessMode::SignedUrl => __('Unauthorized: connect with this Star\'s signed URL. Its owner copies it from the Star\'s Access page in Nexus.'),
                    StarAccessMode::OAuth => __('Unauthorized: sign in to Nexus to use this Star. MCP clients start signing in from this response, and the Star\'s owner approves the client in Nexus.'),
                    StarAccessMode::Token, null => __('Unauthorized: send one of this Star\'s tokens as "Authorization: Bearer nxs_…". Its owner creates them on the Star\'s Access page in Nexus.'),
                },
            ],
        ], 401, [
            'WWW-Authenticate' => $this->challenge($star, $sentToken),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * `Bearer realm="nexus"`, with `invalid_token` when the request carried
     * a token that didn't let it in. In OAuth mode the challenge names the
     * Star's protected resource metadata and the scope to ask for, which is
     * how MCP clients discover where to sign in (RFC 9728).
     */
    private function challenge(?Star $star, bool $sentToken): string
    {
        $parameters = ['realm="nexus"'];

        if ($star?->access_mode === StarAccessMode::OAuth) {
            $parameters[] = 'resource_metadata="'.$star->protectedResourceMetadataUrl().'"';
            $parameters[] = 'scope="'.Registrar::OAUTH_SCOPE.'"';
        }

        if ($sentToken) {
            $parameters[] = 'error="invalid_token"';
        }

        return 'Bearer '.implode(', ', $parameters);
    }
}
