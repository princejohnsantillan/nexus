<?php

declare(strict_types=1);

use App\Enums\StarAccessMode;
use App\Models\Star;
use App\Models\User;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Laravel\Socialite\Facades\Socialite;
use Tests\Support\DownstreamCanary;
use Tests\Support\StarClient;
use Tests\Support\StarOAuthFlow;

/*
 * Signing in to Nexus (GitHub sign-in, and MCP clients signing in to a Star
 * with OAuth) when the other side sends text Nexus doesn't know
 * (DownstreamCanary::TEXT) refuses it without logging it.
 */

beforeEach(function (): void {
    config(['app.url' => 'https://nexus.test']);
    $this->canary = DownstreamCanary::watch();
    $this->user = User::factory()->create();
});

describe('GitHub sign-in', function (): void {
    it('reports a refusal from GitHub in Nexus\'s words', function (): void {
        $this->get(route('auth.github.callback', ['error' => DownstreamCanary::TEXT, 'error_description' => DownstreamCanary::TEXT, 'state' => 'abc']))->assertRedirect(route('home'));

        expect(session('toast.text'))->not->toContain(DownstreamCanary::TEXT)
            ->and($this->canary->sightings())->toBe([]);
    });

    it('reports a failed sign-in in Nexus\'s words, logging only that it failed', function (): void {
        Socialite::fake('github', fn () => throw new ClientException(
            'Client error: `POST https://github.com/login/oauth/access_token` resulted in a `401 Unauthorized` response: '.DownstreamCanary::TEXT,
            new PsrRequest('POST', 'https://github.com/login/oauth/access_token'),
            new PsrResponse(401, [], DownstreamCanary::TEXT),
        ));

        $this->get(route('auth.github.callback'))->assertRedirect(route('home'));

        expect(session('toast.text'))->not->toContain(DownstreamCanary::TEXT)
            ->and($this->canary->sightings())->toBe([]);
    });
});

describe('OAuth to Nexus', function (): void {
    beforeEach(function (): void {
        $this->star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create();
        $this->clientId = StarOAuthFlow::register($this->star);
    });

    it('refuses a token request carrying text it doesn\'t know', function (array $request): void {
        $this->post('/oauth/token', $request)->assertClientError();

        expect($this->canary->sightings())->toBe([]);
    })->with([
        'an unknown client' => [['grant_type' => 'authorization_code', 'client_id' => DownstreamCanary::TEXT, 'code' => DownstreamCanary::TEXT, 'code_verifier' => DownstreamCanary::TEXT, 'redirect_uri' => StarOAuthFlow::REDIRECT_URI]],
        'an unknown code' => [fn (): array => ['grant_type' => 'authorization_code', 'client_id' => $this->clientId, 'code' => DownstreamCanary::TEXT, 'code_verifier' => DownstreamCanary::TEXT, 'redirect_uri' => StarOAuthFlow::REDIRECT_URI]],
        'an unknown refresh token' => [fn (): array => ['grant_type' => 'refresh_token', 'client_id' => $this->clientId, 'refresh_token' => DownstreamCanary::TEXT]],
        'an unknown scope' => [fn (): array => ['grant_type' => 'refresh_token', 'client_id' => $this->clientId, 'refresh_token' => DownstreamCanary::TEXT, 'scope' => DownstreamCanary::TEXT]],
        'an unknown grant' => [['grant_type' => DownstreamCanary::TEXT]],
    ]);

    it('refuses an authorization request carrying text it doesn\'t know', function (Closure $query): void {
        $response = $this->actingAs($this->user)->get('/oauth/authorize?'.http_build_query($query($this->clientId)));

        expect($response->getStatusCode())->toBeLessThan(500)
            ->and($this->canary->sightings())->toBe([]);
    })->with([
        'an unknown client' => [fn (string $clientId): array => ['client_id' => DownstreamCanary::TEXT, 'redirect_uri' => StarOAuthFlow::REDIRECT_URI, 'response_type' => 'code', 'state' => DownstreamCanary::TEXT]],
        'another redirect URI' => [fn (string $clientId): array => ['client_id' => $clientId, 'redirect_uri' => 'https://'.DownstreamCanary::TEXT.'.example.com/callback', 'response_type' => 'code']],
        'an unknown scope' => [fn (string $clientId): array => ['client_id' => $clientId, 'redirect_uri' => StarOAuthFlow::REDIRECT_URI, 'response_type' => 'code', 'scope' => DownstreamCanary::TEXT, 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256', 'state' => DownstreamCanary::TEXT]],
        'an unknown response type' => [fn (string $clientId): array => ['client_id' => $clientId, 'redirect_uri' => StarOAuthFlow::REDIRECT_URI, 'response_type' => DownstreamCanary::TEXT]],
    ]);

    it('tells a client whose ID isn\'t a UUID that it is unknown, on every database', function (): void {
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => DownstreamCanary::TEXT, 'refresh_token' => DownstreamCanary::TEXT])
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_client');

        $this->actingAs($this->user)
            ->get('/oauth/authorize?'.http_build_query(['client_id' => DownstreamCanary::TEXT, 'redirect_uri' => StarOAuthFlow::REDIRECT_URI, 'response_type' => 'code']))
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_client');

        expect($this->canary->sightings())->toBe([]);
    });

    it('refuses an access token it didn\'t issue', function (string $token): void {
        StarClient::for($this->star)->withToken($token)->listTools()->assertUnauthorized();

        expect($this->canary->sightings())->toBe([]);
    })->with([
        'not a token' => [DownstreamCanary::TEXT],
        'a forged token' => [implode('.', [base64_encode('{"alg":"RS256"}'), base64_encode('{"aud":"'.DownstreamCanary::TEXT.'"}'), DownstreamCanary::TEXT])],
    ]);
});
