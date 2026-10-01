<?php

declare(strict_types=1);

use App\Actions\ChangeStarAccessMode;
use App\Enums\StarAccessMode;
use App\Models\Star;
use App\Models\StarToken;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\StarClient;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->star = Star::factory()->for($this->user)->create(['name' => 'Work', 'slug' => 'work']);

    $this->actingAs($this->user);
});

/**
 * Match the Flux toast with this text and variant.
 *
 * @return Closure(string, array<string, mixed>): bool
 */
function accessToast(string $text, string $variant = 'success'): Closure
{
    return fn (string $event, array $params): bool => $params['slots']['text'] === $text && $params['dataset']['variant'] === $variant;
}

it('lists the Star\'s tokens by name and prefix, with when each was last used', function (): void {
    $this->travelTo(now()->startOfDay());
    StarToken::factory()->for($this->star)->plain('nxs_abcdefgh'.str_repeat('x', 32))->create(['name' => 'Laptop', 'last_used_at' => now()->subHours(2)]);
    StarToken::factory()->for($this->star)->plain('nxs_ijklmnop'.str_repeat('y', 32))->create(['name' => 'Desktop']);
    StarToken::factory()->create(['name' => 'Someone else\'s']);

    $this->get(route('stars.access', $this->star))
        ->assertOk()
        ->assertSee('<title>Star access · Nexus</title>', escape: false)
        ->assertSeeTextInOrder(['Overview', 'Tools', 'Access', 'Bearer token', 'NEXUS_WORK_TOKEN'])
        ->assertSeeTextInOrder(['Desktop', 'nxs_ijklmnop…', 'Never', 'Laptop', 'nxs_abcdefgh…', '2 hours ago'])
        ->assertDontSeeText('Someone else\'s')
        ->assertDontSee(str_repeat('x', 32));
});

it('says when the Star has no tokens yet', function (): void {
    $this->get(route('stars.access', $this->star))->assertSeeText('No tokens yet');
});

it('creates a token, shows it once and keeps only its hash', function (): void {
    $page = Livewire::test('pages::stars.access', ['star' => $this->star])
        ->set('name', ' Claude Code ')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSet('name', '')
        ->assertDispatched('modal-show', name: 'new-token');

    $plainToken = $page->get('newToken');
    $token = $this->star->tokens()->sole();

    expect($plainToken)->toMatch('/^nxs_[A-Za-z0-9]{40}$/')
        ->and($token->name)->toBe('Claude Code')
        ->and($token->token_hash)->toBe(hash('sha256', $plainToken))
        ->and($token->prefix)->toBe(substr($plainToken, 0, 12))
        ->and(json_encode($token->getAttributes()))->not->toContain($plainToken);

    $page->assertSee($plainToken)
        ->call('forgetNewToken')
        ->assertSet('newToken', null)
        ->assertDontSee($plainToken);

    StarClient::for($this->star)->withToken($plainToken)->connect()->assertOk();
});

it('requires a token name', function (): void {
    Livewire::test('pages::stars.access', ['star' => $this->star])
        ->set('name', ' ')
        ->call('create')
        ->assertHasErrors(['name' => 'The name field is required.']);

    expect($this->star->tokens()->count())->toBe(0);
});

it('stops at the tokens limit with a friendly message', function (): void {
    config(['nexus.limits.tokens_per_star' => 2]);
    StarToken::factory()->for($this->star)->count(2)->create();

    Livewire::test('pages::stars.access', ['star' => $this->star])
        ->assertSeeText('Token limit reached')
        ->assertSeeText('This Star has 2 tokens, the most a Star can have. Revoke one to create another.')
        ->set('name', 'Third')
        ->call('create')
        ->assertHasErrors(['limit' => 'This Star has 2 tokens, the most a Star can have. Revoke one to create another.']);

    expect($this->star->tokens()->count())->toBe(2);
});

it('revokes a token after confirming, so it stops working', function (): void {
    $plainToken = StarToken::generate();
    $token = StarToken::factory()->for($this->star)->plain($plainToken)->create(['name' => 'Laptop']);

    Livewire::test('pages::stars.access', ['star' => $this->star])
        ->assertSeeText('Revoke Laptop?')
        ->call('revoke', $token->id)
        ->assertDispatched('toast-show', accessToast('Revoked Laptop. Clients using it can no longer reach this Star.'))
        ->assertSeeText('No tokens yet');

    $this->assertModelMissing($token);
    StarClient::for($this->star)->withToken($plainToken)->connect()->assertUnauthorized();
});

it('does not revoke another Star\'s token', function (): void {
    $otherToken = StarToken::factory()->for(Star::factory()->for($this->user))->create();

    Livewire::test('pages::stars.access', ['star' => $this->star])
        ->call('revoke', $otherToken->id)
        ->assertNotFound();

    $this->assertModelExists($otherToken);
});

it('escapes token names', function (): void {
    StarToken::factory()->for($this->star)->create(['name' => '<script>alert("token")</script>']);

    $this->get(route('stars.access', $this->star))
        ->assertOk()
        ->assertDontSee('<script>alert("token")</script>', escape: false);
});

it('shows the signed URL to copy in signed-URL mode, with no tokens', function (): void {
    $this->star->forceFill(['access_mode' => StarAccessMode::SignedUrl])->save();

    $this->get(route('stars.access', $this->star))
        ->assertOk()
        ->assertSeeTextInOrder(['Access mode', 'Signed URL', 'Signed URL', 'Anyone who has this URL can use the Star', 'Rotate URL'])
        ->assertSee('value="'.e($this->star->signedUrl()).'"', escape: false)
        ->assertDontSeeText('Create token');
});

it('rotates the signed URL after confirming, so the old one stops working', function (): void {
    $this->star->forceFill(['access_mode' => StarAccessMode::SignedUrl])->save();
    $oldUrl = $this->star->signedUrl();

    $page = Livewire::test('pages::stars.access', ['star' => $this->star])
        ->assertSeeText('Rotate the signed URL?')
        ->call('rotateSignedUrl')
        ->assertDispatched('toast-show', accessToast('Rotated. The old URL no longer works, so give your clients the new one.'));

    $newUrl = $this->star->refresh()->signedUrl();

    expect($this->star->signed_url_version)->toBe(2)
        ->and($newUrl)->not->toBe($oldUrl);
    $page->assertSee(e($newUrl), escape: false)->assertDontSee(e($oldUrl), escape: false);
    StarClient::for($this->star)->at($oldUrl)->connect()->assertUnauthorized();
    StarClient::for($this->star)->at($newUrl)->connect()->assertOk();
});

it('offers each access mode with a short explanation, the Star\'s own chosen', function (): void {
    Livewire::test('pages::stars.access', ['star' => $this->star])
        ->assertSet('accessMode', 'token')
        ->assertSeeTextInOrder([
            'Bearer token', 'Clients send a token you create for each of them in a header.',
            'Signed URL', 'One secret URL that works by itself, for clients that only take a URL.',
        ]);
});

it('switches to a signed URL after confirming, revoking the Star\'s tokens', function (): void {
    $plainToken = StarToken::generate();
    $token = StarToken::factory()->for($this->star)->plain($plainToken)->create();

    $page = Livewire::test('pages::stars.access', ['star' => $this->star])
        ->set('accessMode', 'signed_url')
        ->assertSeeText('Switch to Signed URL?')
        ->assertSeeText('Its tokens are revoked, so clients using them stop reaching the Star at once.')
        ->call('changeAccessMode')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', accessToast('This Star now uses a signed URL. Copy it below and give it to your clients.'));

    expect($this->star->refresh()->access_mode)->toBe(StarAccessMode::SignedUrl);
    $page->assertSee(e($this->star->signedUrl()), escape: false);
    $this->assertModelMissing($token);
    StarClient::for($this->star)->withToken($plainToken)->connect()->assertUnauthorized();
    StarClient::for($this->star)->at($this->star->signedUrl())->connect()->assertOk();
});

it('switches back to tokens after confirming, so the signed URL never works again', function (): void {
    $this->star->forceFill(['access_mode' => StarAccessMode::SignedUrl])->save();
    $oldUrl = $this->star->signedUrl();

    Livewire::test('pages::stars.access', ['star' => $this->star])
        ->set('accessMode', 'token')
        ->assertSeeText('Switch to Bearer token?')
        ->assertSeeText('Its signed URL stops working at once, and switching back later gives it a new one.')
        ->call('changeAccessMode')
        ->assertDispatched('toast-show', accessToast('This Star now uses tokens. Create one below for each client.'))
        ->assertSeeText('No tokens yet');

    expect($this->star->refresh()->access_mode)->toBe(StarAccessMode::Token);
    StarClient::for($this->star)->at($oldUrl)->connect()->assertUnauthorized();

    Livewire::test('pages::stars.access', ['star' => $this->star])
        ->set('accessMode', 'signed_url')
        ->call('changeAccessMode');

    StarClient::for($this->star->refresh())->at($oldUrl)->connect()->assertUnauthorized();
    StarClient::for($this->star)->at($this->star->signedUrl())->connect()->assertOk();
});

it('refuses an access mode that does not exist', function (): void {
    Livewire::test('pages::stars.access', ['star' => $this->star])
        ->set('accessMode', 'open')
        ->call('changeAccessMode')
        ->assertHasErrors(['accessMode']);

    expect($this->star->refresh()->access_mode)->toBe(StarAccessMode::Token);
});

it('creates no token once the Star has switched away from tokens elsewhere', function (): void {
    $page = Livewire::test('pages::stars.access', ['star' => $this->star]);
    resolve(ChangeStarAccessMode::class)->handle($this->star->fresh(), StarAccessMode::SignedUrl);

    $page->set('name', 'Laptop')
        ->call('create')
        ->assertHasErrors(['limit' => 'This Star no longer uses tokens. Reload the page to see how clients reach it.']);

    expect($this->star->tokens()->count())->toBe(0);
});

it('rotates nothing once the Star has switched away from its signed URL elsewhere', function (): void {
    $this->star->forceFill(['access_mode' => StarAccessMode::SignedUrl])->save();
    $page = Livewire::test('pages::stars.access', ['star' => $this->star]);
    resolve(ChangeStarAccessMode::class)->handle($this->star->fresh(), StarAccessMode::Token);
    $version = $this->star->fresh()->signed_url_version;

    $page->call('rotateSignedUrl')
        ->assertDispatched('toast-show', accessToast('This Star no longer uses a signed URL. Reload the page to see how clients reach it.', 'warning'));

    expect($this->star->fresh()->signed_url_version)->toBe($version);
});
