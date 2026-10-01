<?php

declare(strict_types=1);

use App\Enums\ActivityStatus;
use App\Enums\StarAccessMode;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\StarOAuthClient;
use App\Models\User;
use Illuminate\Support\Uri;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\Support\FakeMcpServer;
use Tests\Support\StarClient;
use Tests\Support\StarOAuthFlow;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->wiki = Connection::factory()->for($this->owner)->connected()->create(['handle' => 'wiki']);
    ConnectionTool::factory()->for($this->wiki)->create([
        'name' => 'search',
        'definition' => '{"name":"search","inputSchema":{"type":"object"},"annotations":{"readOnlyHint":true}}',
        'read_only' => true,
    ]);
    $this->star = Star::factory()->for($this->owner)->including($this->wiki)->withAccessMode(StarAccessMode::OAuth)->create(['name' => 'Work']);
    $this->clientId = StarOAuthFlow::register($this->star, 'Claude');
});

it('lets an approved client into the Star with its access token, in both protocol eras', function (string $protocolVersion): void {
    $tokens = StarOAuthFlow::signIn($this->owner, $this->clientId);

    StarClient::for($this->star)->withToken($tokens['access_token'])->speaking($protocolVersion)->connect()->assertOk();
    StarClient::for($this->star)->withToken($tokens['access_token'])->speaking($protocolVersion)->listTools()
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'wiki__search');
})->with(['2026-07-28', '2025-11-25']);

it('issues access tokens for an hour and refresh tokens for 30 days, scoped to mcp:use', function (): void {
    $tokens = StarOAuthFlow::signIn($this->owner, $this->clientId);
    $token = Token::query()->sole();

    // The OAuth server dates tokens by the system clock, which tests can't travel.
    expect($tokens['token_type'])->toBe('Bearer')
        ->and($tokens['expires_in'])->toBe(3600)
        ->and($tokens['refresh_token'])->toBeString()->not->toBeEmpty()
        ->and($token->user_id)->toBe($this->owner->id)
        ->and($token->client_id)->toBe($this->clientId)
        ->and($token->scopes)->toBe(['mcp:use'])
        ->and(abs($token->expires_at?->diffInSeconds(now()->addHour()) ?? 60))->toBeLessThan(5)
        ->and(abs(RefreshToken::query()->sole()->expires_at?->diffInSeconds(now()->addDays(30)) ?? 60))->toBeLessThan(5);
});

it('gives a client that asks for no scope the mcp:use scope', function (): void {
    $verifier = str_repeat('v', 64);
    $url = str_replace('&scope=mcp%3Ause', '', StarOAuthFlow::authorizeUrl($this->clientId, $verifier));

    $this->actingAs($this->owner)->get($url)->assertOk();
    $code = Uri::of((string) StarOAuthFlow::approve()->headers->get('Location'))->query()->get('code');
    $tokens = StarOAuthFlow::exchange($this->clientId, (string) $code, $verifier)->assertOk()->json();

    expect(Token::query()->sole()->scopes)->toBe(['mcp:use']);
    StarClient::for($this->star)->withToken($tokens['access_token'])->connect()->assertOk();
});

it('renews the access token with the refresh token, and the new one works', function (): void {
    $tokens = StarOAuthFlow::signIn($this->owner, $this->clientId);

    $renewed = StarOAuthFlow::refresh($this->clientId, $tokens['refresh_token'])->assertOk()->json();

    expect($renewed['access_token'])->not->toBe($tokens['access_token']);
    StarClient::for($this->star)->withToken($renewed['access_token'])->connect()->assertOk();
    StarClient::for($this->star)->withToken($tokens['access_token'])->connect()->assertUnauthorized();
    StarOAuthFlow::refresh($this->clientId, $tokens['refresh_token'])->assertBadRequest();
});

it('refuses a revoked access token as invalid, so the client renews it', function (): void {
    $tokens = StarOAuthFlow::signIn($this->owner, $this->clientId);

    Token::query()->update(['revoked' => true]);

    StarClient::for($this->star)->withToken($tokens['access_token'])->connect()
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="nexus", resource_metadata="'.$this->star->protectedResourceMetadataUrl().'", scope="mcp:use", error="invalid_token"');
});

it('never lets a token for one Star into another, even the same user\'s', function (Closure $otherStar): void {
    $tokens = StarOAuthFlow::signIn($this->owner, $this->clientId);

    $challenge = StarClient::for($otherStar($this->owner))->withToken($tokens['access_token'])->connect()
        ->assertUnauthorized()
        ->headers->get('WWW-Authenticate');

    expect($challenge)->toEndWith('error="invalid_token"');
})->with([
    'another Star in OAuth mode' => fn (User $owner): Star => Star::factory()->for($owner)->withAccessMode(StarAccessMode::OAuth)->create(),
    'a Star in token mode' => fn (User $owner): Star => Star::factory()->for($owner)->create(),
    'a Star in signed-URL mode' => fn (User $owner): Star => Star::factory()->for($owner)->withAccessMode(StarAccessMode::SignedUrl)->create(),
    'someone else\'s Star in OAuth mode' => fn (User $owner): Star => Star::factory()->withAccessMode(StarAccessMode::OAuth)->create(),
]);

it('refuses a token whose user does not own the Star', function (): void {
    $tokens = StarOAuthFlow::signIn($this->owner, $this->clientId);

    $this->star->forceFill(['user_id' => User::factory()->create()->id])->save();

    StarClient::for($this->star)->withToken($tokens['access_token'])->connect()->assertUnauthorized();
});

it('refuses the token of a client the owner never approved', function (): void {
    $tokens = StarOAuthFlow::signIn($this->owner, $this->clientId);

    StarOAuthClient::query()->update(['approved_at' => null]);

    StarClient::for($this->star)->withToken($tokens['access_token'])->connect()->assertUnauthorized();
});

it('records calls as made with OAuth, under the client\'s name', function (): void {
    FakeMcpServer::at()->onCall('search', fn (): array => ['content' => [['type' => 'text', 'text' => 'Found it']]]);
    $tokens = StarOAuthFlow::signIn($this->owner, $this->clientId);

    StarClient::for($this->star)->withToken($tokens['access_token'])->callTool('wiki__search')->assertOk();

    $entry = ActivityEntry::query()->sole();

    expect($entry->via)->toBe(StarAccessMode::OAuth)
        ->and($entry->client_name)->toBe('Claude')
        ->and($entry->status)->toBe(ActivityStatus::Ok)
        ->and($entry->star_id)->toBe($this->star->id);
});

it('keeps the first 100 characters of a long client name in activity', function (): void {
    FakeMcpServer::at()->onCall('search', fn (): array => ['content' => []]);
    $clientId = StarOAuthFlow::register($this->star, str_repeat('n', 255));
    $tokens = StarOAuthFlow::signIn($this->owner, $clientId);

    StarClient::for($this->star)->withToken($tokens['access_token'])->callTool('wiki__search')->assertOk();

    expect(ActivityEntry::query()->sole()->client_name)->toBe(str_repeat('n', 100));
});

it('notes when each connected app last reached the Star', function (): void {
    $tokens = StarOAuthFlow::signIn($this->owner, $this->clientId);
    $this->travelTo(now()->addMinutes(5)->startOfSecond());

    StarClient::for($this->star)->withToken($tokens['access_token'])->connect()->assertOk();

    expect(StarOAuthClient::query()->sole()->last_used_at?->toIso8601String())->toBe(now()->toIso8601String());
});

it('limits calls per minute for each connected app, however often its token is renewed', function (): void {
    config(['nexus.limits.calls_per_minute' => 2]);
    $tokens = StarOAuthFlow::signIn($this->owner, $this->clientId);
    $other = StarOAuthFlow::signIn($this->owner, StarOAuthFlow::register($this->star, 'Cursor'));

    StarClient::for($this->star)->withToken($tokens['access_token'])->connect()->assertOk();
    $renewed = StarOAuthFlow::refresh($this->clientId, $tokens['refresh_token'])->json('access_token');
    StarClient::for($this->star)->withToken($renewed)->listTools()->assertOk();
    StarClient::for($this->star)->withToken($renewed)->listTools()->assertTooManyRequests();

    StarClient::for($this->star)->withToken($other['access_token'])->listTools()->assertOk();
});

it('refuses a revoked client\'s tokens, and lets it renew none', function (): void {
    $tokens = StarOAuthFlow::signIn($this->owner, $this->clientId);

    Client::query()->whereKey($this->clientId)->update(['revoked' => true]);

    StarClient::for($this->star)->withToken($tokens['access_token'])->connect()->assertUnauthorized();
    StarOAuthFlow::refresh($this->clientId, $tokens['refresh_token'])->assertUnauthorized();
});
