<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Star;
use App\Models\User;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plays an MCP client signing in to a Star in OAuth mode, through Nexus's
 * own routes: it registers itself, sends the user to the consent screen
 * with PKCE (S256), and exchanges the code the approval sends back.
 *
 *     $clientId = StarOAuthFlow::register($star);
 *     $tokens = StarOAuthFlow::signIn($owner, $clientId);
 *     StarClient::for($star)->withToken($tokens['access_token'])->listTools();
 *
 * Each sign-in uses a fresh code verifier and state. Token requests forget
 * values memoized with once(), such as Passport's clients, as a request in
 * its own PHP process would.
 */
final class StarOAuthFlow
{
    /**
     * Where claude.ai asks to be sent back.
     */
    public const string REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

    /**
     * Register a client with the Star, as claude.ai does, and return its client id.
     */
    public static function register(Star $star, string $name = 'Claude', string $redirectUri = self::REDIRECT_URI): string
    {
        $clientId = self::registering($star, ['client_name' => $name, 'redirect_uris' => [$redirectUri]])->assertCreated()->json('client_id');

        return is_string($clientId) ? $clientId : throw new \UnexpectedValueException('The registration returned no client id.');
    }

    /**
     * Send a registration request with this client metadata.
     *
     * @param  array<string, mixed>  $metadata
     * @return TestResponse<Response>
     */
    public static function registering(Star $star, array $metadata): TestResponse
    {
        return test()->postJson(route('mcp.oauth.register', $star), $metadata);
    }

    /**
     * The authorization URL the client sends the user to.
     */
    public static function authorizeUrl(string $clientId, string $verifier = 'unused-verifier-unused-verifier-unused-verifier', string $state = 'state-1', string $redirectUri = self::REDIRECT_URI): string
    {
        return route('passport.authorizations.authorize', [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Open the consent screen as the user.
     *
     * @return TestResponse<Response>
     */
    public static function consent(User $user, string $clientId, string $verifier = 'unused-verifier-unused-verifier-unused-verifier'): TestResponse
    {
        return test()->actingAs($user)->get(self::authorizeUrl($clientId, $verifier));
    }

    /**
     * Press Approve on the consent screen last opened.
     *
     * @return TestResponse<Response>
     */
    public static function approve(): TestResponse
    {
        return test()->post(route('passport.authorizations.approve'), ['auth_token' => session('authToken')]);
    }

    /**
     * Sign in end to end as the user: open the consent screen, approve, and
     * exchange the code for tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}
     */
    public static function signIn(User $user, string $clientId, string $redirectUri = self::REDIRECT_URI): array
    {
        $verifier = Str::random(64);
        $state = Str::random(16);

        test()->actingAs($user)->get(self::authorizeUrl($clientId, $verifier, $state, $redirectUri))->assertOk();

        $callback = Uri::of((string) self::approve()->assertRedirect()->headers->get('Location'));

        test()->assertSame($state, $callback->query()->get('state'));

        /** @var array{access_token: string, refresh_token: string, expires_in: int, token_type: string} */
        return self::exchange($clientId, (string) $callback->query()->get('code'), $verifier, $redirectUri)->assertOk()->json();
    }

    /**
     * Exchange an authorization code at the token endpoint.
     *
     * @return TestResponse<Response>
     */
    public static function exchange(string $clientId, string $code, string $verifier, string $redirectUri = self::REDIRECT_URI): TestResponse
    {
        Once::flush();

        return test()->post(route('passport.token'), [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
            'code' => $code,
        ]);
    }

    /**
     * Renew an access token with a refresh token.
     *
     * @return TestResponse<Response>
     */
    public static function refresh(string $clientId, string $refreshToken): TestResponse
    {
        Once::flush();

        return test()->post(route('passport.token'), [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ]);
    }
}
