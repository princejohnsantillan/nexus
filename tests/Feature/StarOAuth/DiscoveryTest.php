<?php

declare(strict_types=1);

use App\Enums\StarAccessMode;
use App\Models\Star;
use App\Models\User;
use Tests\Support\StarClient;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create(['name' => 'Work']);
});

it('answers a client without a token with a challenge naming the Star\'s protected resource metadata', function (string $protocolVersion): void {
    $metadataUrl = url('/.well-known/oauth-protected-resource/mcp/'.$this->star->public_id);

    StarClient::for($this->star)->speaking($protocolVersion)->connect()
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="nexus", resource_metadata="'.$metadataUrl.'", scope="mcp:use"')
        ->assertExactJson([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32001,
                'message' => 'Unauthorized: sign in to Nexus to use this Star. MCP clients start signing in from this response, and the Star\'s owner approves the client in Nexus.',
            ],
        ]);
})->with(['2026-07-28', '2025-11-25']);

it('says a token it refused is invalid, so the client renews it or signs in again', function (string $token): void {
    StarClient::for($this->star)->withToken($token)->connect()
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="nexus", resource_metadata="'.$this->star->protectedResourceMetadataUrl().'", scope="mcp:use", error="invalid_token"');
})->with([
    'not a JWT' => 'not-a-token',
    'a Star token' => 'nxs_'.str_repeat('a', 40),
    'a JWT Nexus didn\'t sign' => fn (): string => implode('.', array_map(
        fn (string $part): string => rtrim(strtr(base64_encode($part), '+/', '-_'), '='),
        ['{"alg":"RS256","typ":"JWT"}', '{"aud":"1","scopes":["mcp:use"]}', 'not a signature'],
    )),
]);

it('publishes the Star\'s protected resource metadata, naming its own issuer', function (): void {
    $this->getJson('/.well-known/oauth-protected-resource/mcp/'.$this->star->public_id)
        ->assertOk()
        ->assertExactJson([
            'resource' => url('/mcp/'.$this->star->public_id),
            'authorization_servers' => [url('/oauth/stars/'.$this->star->public_id)],
            'scopes_supported' => ['mcp:use'],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Nexus: Work',
        ]);
});

it('publishes the issuer\'s authorization server metadata, with its own registration endpoint', function (string $wellKnown): void {
    $this->getJson("/.well-known/{$wellKnown}/oauth/stars/".$this->star->public_id)
        ->assertOk()
        ->assertExactJson([
            'issuer' => url('/oauth/stars/'.$this->star->public_id),
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/oauth/token'),
            'registration_endpoint' => url('/oauth/stars/'.$this->star->public_id.'/register'),
            'scopes_supported' => ['mcp:use'],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
        ]);
})->with(['oauth-authorization-server', 'openid-configuration']);

it('gives every Star its own issuer and registration endpoint', function (): void {
    $other = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create();

    $this->getJson('/.well-known/oauth-authorization-server/oauth/stars/'.$other->public_id)
        ->assertJsonPath('issuer', url('/oauth/stars/'.$other->public_id))
        ->assertJsonPath('registration_endpoint', url('/oauth/stars/'.$other->public_id.'/register'));
});

it('publishes no OAuth metadata for a Star in another mode, or none at all', function (Closure $star): void {
    $publicId = $star();

    $this->getJson('/.well-known/oauth-protected-resource/mcp/'.$publicId)->assertNotFound();
    $this->getJson('/.well-known/oauth-authorization-server/oauth/stars/'.$publicId)->assertNotFound();
    $this->getJson('/.well-known/openid-configuration/oauth/stars/'.$publicId)->assertNotFound();
    $this->get('/.well-known/oauth-protected-resource/mcp/'.$publicId)->assertNotFound();
})->with([
    'token mode' => fn (): string => Star::factory()->create()->public_id,
    'signed-URL mode' => fn (): string => Star::factory()->withAccessMode(StarAccessMode::SignedUrl)->create()->public_id,
    'unknown' => Star::newPublicId(...),
    'not a public id' => fn (): string => 'NOT-A-STAR',
]);

it('keeps the challenge of a Star in another mode free of OAuth discovery', function (StarAccessMode $accessMode): void {
    $star = Star::factory()->withAccessMode($accessMode)->create();

    StarClient::for($star)->connect()
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="nexus"');
})->with([StarAccessMode::Token, StarAccessMode::SignedUrl]);
