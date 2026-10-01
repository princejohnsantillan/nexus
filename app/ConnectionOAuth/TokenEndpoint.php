<?php

declare(strict_types=1);

namespace App\ConnectionOAuth;

use App\Actions\DetectAccountIdentity;
use App\Exceptions\ConnectionSignInFailed;
use App\Exceptions\DownstreamRequestFailed;
use Illuminate\Http\Client\Response;
use SensitiveParameter;

/**
 * Token requests to an authorization server: exchanging a sign-in's
 * authorization code, and renewing an access token with a refresh token.
 * Both name the resource (RFC 8707) and authenticate the client the way it
 * was set up to, and keep any account the response names (such as Notion's
 * workspace) with the tokens. Neither ever repeats text from the server.
 */
final readonly class TokenEndpoint
{
    /**
     * Errors that say the server can't renew a token right now, not that it
     * never will.
     *
     * @var list<string>
     */
    private const array PASSING_ERRORS = ['server_error', 'temporarily_unavailable'];

    public function __construct(
        private OAuthRequests $requests,
        private DetectAccountIdentity $detectAccountIdentity,
    ) {}

    /**
     * Exchange the authorization code a sign-in returned with, proving it
     * with the PKCE code verifier.
     *
     * @throws ConnectionSignInFailed
     */
    public function exchangeCode(
        string $endpoint,
        OAuthClient $client,
        #[SensitiveParameter] string $code,
        #[SensitiveParameter] string $verifier,
        string $redirectUri,
        string $resource,
    ): IssuedTokens {
        $response = $this->request($endpoint, $client, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $redirectUri,
            'resource' => $resource,
        ]);

        $answer = OAuthRequests::json($response) ?? [];

        if (! $response->successful() || isset($answer['error'])) {
            throw ConnectionSignInFailed::codeRefused($response->status(), $answer['error'] ?? null);
        }

        return IssuedTokens::fromResponse($answer, $this->detectAccountIdentity->fromTokenResponse($answer))
            ?? throw ConnectionSignInFailed::because(__('The server finished the sign-in without giving Nexus an access token.'));
    }

    /**
     * Renew an access token. A server that rotates refresh tokens sends a
     * new one; otherwise the tokens carry none and the old one stays good.
     *
     * @param  float|null  $timeout  Seconds to wait for the server, when that is less than usual.
     *
     * @throws DownstreamRequestFailed signInExpired() when the server refuses the refresh token or the client, renewalFailed() when it can't be reached or answers unusably
     */
    public function renew(string $endpoint, OAuthClient $client, #[SensitiveParameter] string $refreshToken, string $resource, ?float $timeout = null): IssuedTokens
    {
        try {
            $response = $this->request($endpoint, $client, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'resource' => $resource,
            ], $timeout);
        } catch (ConnectionSignInFailed) {
            throw DownstreamRequestFailed::renewalFailed();
        }

        $answer = OAuthRequests::json($response) ?? [];
        $error = $answer['error'] ?? null;
        $mayPass = in_array($response->status(), [408, 429], true) || in_array($error, self::PASSING_ERRORS, true);

        if (! $mayPass && ($response->clientError() || $error !== null)) {
            throw DownstreamRequestFailed::signInExpired();
        }

        if (! $response->successful() || $error !== null) {
            throw DownstreamRequestFailed::renewalFailed();
        }

        return IssuedTokens::fromResponse($answer, $this->detectAccountIdentity->fromTokenResponse($answer)) ?? throw DownstreamRequestFailed::renewalFailed();
    }

    /**
     * @param  array<string, string>  $parameters
     *
     * @throws ConnectionSignInFailed when the server can't be reached
     */
    private function request(string $endpoint, OAuthClient $client, #[SensitiveParameter] array $parameters, ?float $timeout = null): Response
    {
        [$fields, $basicAuth] = $client->credentials();

        return $this->requests->postForm($endpoint, [...$parameters, ...$fields], $basicAuth, $timeout);
    }
}
