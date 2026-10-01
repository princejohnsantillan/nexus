<?php

declare(strict_types=1);

use App\Actions\ChangeStarAccessMode;
use App\Enums\StarAccessMode;
use App\Models\Star;
use App\Models\StarOAuthClient;
use App\Models\User;
use Laravel\Passport\Client;
use Tests\Support\StarOAuthFlow;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create();
});

it('registers a public client and binds it to the Star, not approved yet', function (): void {
    $response = StarOAuthFlow::registering($this->star, [
        'client_name' => 'Claude Code (nexus-work)',
        'redirect_uris' => ['http://localhost:54212/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_method' => 'none',
    ])->assertCreated()
        ->assertJsonPath('redirect_uris', ['http://localhost:54212/callback'])
        ->assertJsonPath('grant_types', ['authorization_code', 'refresh_token'])
        ->assertJsonPath('token_endpoint_auth_method', 'none')
        ->assertJsonPath('scope', 'mcp:use')
        ->assertJsonMissingPath('client_secret');

    $client = Client::query()->findOrFail($response->json('client_id'));
    $binding = StarOAuthClient::query()->sole();

    expect($client->name)->toBe('Claude Code (nexus-work)')
        ->and($client->confidential())->toBeFalse()
        ->and($client->revoked)->toBeFalse()
        ->and($binding->star_id)->toBe($this->star->id)
        ->and($binding->client_id)->toBe($client->id)
        ->and($binding->approved_at)->toBeNull();
});

it('binds each registration to the Star it was made with', function (): void {
    $other = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create();

    $first = StarOAuthFlow::register($this->star);
    $second = StarOAuthFlow::register($other);

    expect(StarOAuthClient::query()->where('client_id', $first)->sole()->star_id)->toBe($this->star->id)
        ->and(StarOAuthClient::query()->where('client_id', $second)->sole()->star_id)->toBe($other->id);
});

it('accepts HTTPS, loopback and desktop app redirect URIs', function (string $redirectUri): void {
    StarOAuthFlow::registering($this->star, ['redirect_uris' => [$redirectUri]])->assertCreated();
})->with([
    'HTTPS' => 'https://claude.ai/api/mcp/auth_callback',
    'localhost, any port' => 'http://localhost:6274/oauth/callback',
    '127.0.0.1' => 'http://127.0.0.1:1455/callback',
    'IPv6 loopback' => 'http://[::1]:8080/callback',
    'Cursor' => 'cursor://anysphere.cursor-mcp/oauth/callback',
    'VS Code' => 'vscode://vscode.github-authentication/did-authenticate',
    'VS Code Insiders' => 'vscode-insiders://vscode.github-authentication/did-authenticate',
    'Windsurf' => 'windsurf://codeium.windsurf/callback',
    'Zed' => 'zed://oauth/callback',
]);

it('refuses other redirect URIs, binding nothing', function (string $redirectUri): void {
    StarOAuthFlow::registering($this->star, ['client_name' => 'Sneaky', 'redirect_uris' => [$redirectUri]])
        ->assertBadRequest()
        ->assertJsonPath('error', 'invalid_redirect_uri');

    expect(Client::query()->count())->toBe(0)
        ->and(StarOAuthClient::query()->count())->toBe(0);
})->with([
    'plain HTTP off loopback' => 'http://evil.example.com/callback',
    'a host that starts like localhost' => 'http://localhost.evil.example.com/callback',
    'an unlisted scheme' => 'evil://callback/here',
    'javascript' => 'javascript:alert(1)',
    'credentials in the URL' => 'https://user:pass@claude.ai/callback',
]);

it('refuses a registration without a redirect URI', function (): void {
    StarOAuthFlow::registering($this->star, ['client_name' => 'Claude'])
        ->assertBadRequest()
        ->assertJsonPath('error', 'invalid_redirect_uri');
});

it('registers with no Star that does not use OAuth, or does not exist', function (Closure $star): void {
    $this->postJson('/oauth/stars/'.$star().'/register', ['redirect_uris' => [StarOAuthFlow::REDIRECT_URI]])->assertNotFound();

    expect(Client::query()->count())->toBe(0);
})->with([
    'token mode' => fn (): string => Star::factory()->create()->public_id,
    'signed-URL mode' => fn (): string => Star::factory()->withAccessMode(StarAccessMode::SignedUrl)->create()->public_id,
    'unknown' => Star::newPublicId(...),
]);

it('stops registering for a Star once it switches away from OAuth', function (): void {
    StarOAuthFlow::register($this->star);
    resolve(ChangeStarAccessMode::class)->handle($this->star, StarAccessMode::Token);

    StarOAuthFlow::registering($this->star, ['redirect_uris' => [StarOAuthFlow::REDIRECT_URI]])->assertNotFound();
});

it('limits registrations per hour for each IP address', function (): void {
    config(['nexus.limits.oauth_registrations_per_hour' => 2]);
    $other = Star::factory()->withAccessMode(StarAccessMode::OAuth)->create();

    StarOAuthFlow::register($this->star);
    StarOAuthFlow::register($other);

    StarOAuthFlow::registering($this->star, ['redirect_uris' => [StarOAuthFlow::REDIRECT_URI]])->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson(route('mcp.oauth.register', $this->star), ['redirect_uris' => [StarOAuthFlow::REDIRECT_URI]])
        ->assertCreated();

    $this->travel(61)->minutes();

    StarOAuthFlow::registering($this->star, ['redirect_uris' => [StarOAuthFlow::REDIRECT_URI]])->assertCreated();
});
