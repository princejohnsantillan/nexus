<?php

declare(strict_types=1);

use App\Actions\CreateStarToken;
use App\Models\Star;
use App\Models\StarToken;
use App\Models\User;
use Tests\Support\StarClient;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->star = Star::factory()->for($this->user)->create();
    $this->token = resolve(CreateStarToken::class)->handle($this->star, 'Laptop');
});

it('lets a client in with one of the Star\'s tokens and notes when the token was used', function (string $protocolVersion): void {
    $this->travelTo(now()->startOfSecond());

    StarClient::for($this->star)->withToken($this->token->plainTextToken)->speaking($protocolVersion)->connect()->assertOk();

    expect($this->token->token->fresh()->last_used_at?->toIso8601String())->toBe(now()->toIso8601String());
})->with(['2026-07-28', '2025-11-25']);

it('asks a client without a token for a bearer token', function (): void {
    StarClient::for($this->star)->connect()
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="nexus"')
        ->assertExactJson([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32001,
                'message' => 'Unauthorized: send one of this Star\'s tokens as "Authorization: Bearer nxs_…". Its owner creates them on the Star\'s Access page in Nexus.',
            ],
        ]);
});

it('refuses a token that is not one of the Star\'s', function (Closure $token): void {
    StarClient::for($this->star)->withToken($token())->connect()
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="nexus", error="invalid_token"');
})->with([
    'another Star\'s, of the same user' => fn (): string => resolve(CreateStarToken::class)->handle(Star::factory()->for(test()->user)->create(), 'Other')->plainTextToken,
    'unknown' => StarToken::generate(...),
    'malformed' => fn (): string => 'not-a-token',
]);

it('refuses a revoked token', function (): void {
    $this->token->token->delete();

    StarClient::for($this->star)->withToken($this->token->plainTextToken)->connect()->assertUnauthorized();
});

it('answers a Star that does not exist as it answers a wrong token', function (): void {
    $plainToken = $this->token->plainTextToken;
    $this->star->delete();

    StarClient::for($this->star)->withToken($plainToken)->connect()
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="nexus", error="invalid_token"');
});

it('limits calls per minute for each token', function (): void {
    config(['nexus.limits.calls_per_minute' => 2]);
    $other = resolve(CreateStarToken::class)->handle($this->star, 'Desktop')->plainTextToken;
    $client = StarClient::for($this->star)->withToken($this->token->plainTextToken);

    $client->connect()->assertOk();
    $client->listTools()->assertOk();
    $client->listTools()->assertTooManyRequests();

    StarClient::for($this->star)->withToken($other)->listTools()->assertOk();
});
