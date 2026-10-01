<?php

declare(strict_types=1);

use App\Actions\CreateStarToken;
use App\Enums\StarAccessMode;
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

it('lets a client in with the Star\'s signed URL alone', function (string $protocolVersion): void {
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::SignedUrl)->create();

    StarClient::for($star)->at($star->signedUrl())->speaking($protocolVersion)->connect()->assertOk();
})->with(['2026-07-28', '2025-11-25']);

it('refuses the Star\'s tokens while it uses a signed URL', function (): void {
    $this->star->forceFill(['access_mode' => StarAccessMode::SignedUrl])->save();

    StarClient::for($this->star)->withToken($this->token->plainTextToken)->connect()
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="nexus", error="invalid_token"');

    expect($this->token->token->fresh()->last_used_at)->toBeNull();
});

it('refuses the Star\'s signed URL while it uses tokens', function (): void {
    StarClient::for($this->star)->at($this->star->signedUrl())->connect()->assertUnauthorized();
});

it('asks a client without the signed URL for it', function (): void {
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::SignedUrl)->create();

    StarClient::for($star)->connect()
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="nexus"')
        ->assertExactJson([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32001,
                'message' => 'Unauthorized: connect with this Star\'s signed URL. Its owner copies it from the Star\'s Access page in Nexus.',
            ],
        ]);
});

it('refuses a signed URL that is not the Star\'s current one', function (Closure $url): void {
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::SignedUrl)->create(['signed_url_version' => 3]);

    StarClient::for($star)->at($url($star))->connect()->assertUnauthorized();
})->with([
    'from before a rotation' => function (Star $star): string {
        $url = $star->signedUrl();
        $star->increment('signed_url_version');

        return $url;
    },
    'with its version changed' => fn (Star $star): string => str_replace('v=3', 'v=2', $star->signedUrl()),
    'with its signature changed' => fn (Star $star): string => preg_replace('/signature=[0-9a-f]{4}/', 'signature=0000', $star->signedUrl()) ?? '',
    'without its signature' => fn (Star $star): string => $star->endpointUrl().'?v=3',
    'another Star\'s, at this Star\'s path' => function (Star $star): string {
        $other = Star::factory()->for($star->user)->withAccessMode(StarAccessMode::SignedUrl)->create(['signed_url_version' => 3]);

        return str_replace($other->public_id, $star->public_id, $other->signedUrl());
    },
]);

it('limits calls per minute for each version of a Star\'s signed URL', function (): void {
    config(['nexus.limits.calls_per_minute' => 2]);
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::SignedUrl)->create();
    $other = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::SignedUrl)->create();
    $client = StarClient::for($star)->at($star->signedUrl());

    $client->connect()->assertOk();
    $client->listTools()->assertOk();
    $client->listTools()->assertTooManyRequests();

    StarClient::for($other)->at($other->signedUrl())->listTools()->assertOk();

    $star->rotateSignedUrl();

    StarClient::for($star)->at($star->signedUrl())->listTools()->assertOk();
});

it('stops the old signed URL working once it is rotated', function (): void {
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::SignedUrl)->create();
    $oldUrl = $star->signedUrl();

    $star->rotateSignedUrl();

    expect($star->signedUrl())->not->toBe($oldUrl);
    StarClient::for($star)->at($oldUrl)->connect()->assertUnauthorized();
    StarClient::for($star)->at($star->signedUrl())->connect()->assertOk();
});
