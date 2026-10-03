<?php

declare(strict_types=1);

use App\Actions\ChangeStarAccessMode;
use App\Enums\StarAccessMode;
use App\Models\Star;
use App\Models\StarOAuthClient;
use App\Models\StarToken;
use App\Models\User;
use Dom\HTMLDocument;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Livewire\Livewire;
use Tests\Support\StarClient;
use Tests\Support\StarOAuthFlow;

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

/**
 * Text with its runs of whitespace squished to single spaces.
 */
function squishedAccessText(?string $text): string
{
    return trim((string) preg_replace('/\s+/', ' ', (string) $text));
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
        ->assertSet('name', '');

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
        ->assertDontSee($plainToken)
        ->assertSeeText('Create a token');

    StarClient::for($this->star)->withToken($plainToken)->connect()->assertOk();
});

it('shows the new token as the line that puts it in the shell, with the way on to setting up a client', function (): void {
    $page = Livewire::test('pages::stars.access', ['star' => $this->star])
        ->set('name', 'Cursor')
        ->call('create');

    $plainToken = $page->get('newToken');
    $reveal = HTMLDocument::createFromString($page->html(), LIBXML_NOERROR)->querySelector('[data-new-token]');

    expect([
        $reveal?->querySelector('[data-code-panel-title]')?->textContent,
        $reveal?->querySelector('[data-code-panel] pre')?->textContent,
        squishedAccessText($reveal?->querySelector('[data-new-token-once]')?->textContent),
        squishedAccessText($reveal?->querySelector('[data-new-token-next]')?->textContent),
        $reveal?->querySelector('[data-new-token-next]')?->getAttribute('href'),
    ])->toBe([
        '~/.zshrc',
        'export NEXUS_WORK_TOKEN='.$plainToken,
        'This is the only time Nexus shows this token. If you lose it, create another and revoke this one.',
        'Next: set up a client',
        route('stars.show', $this->star).'#setup',
    ]);
});

it('opens the form for a new token with the name the client setup suggests', function (): void {
    $page = Livewire::withQueryParams(['new_token' => 'Cursor'])->test('pages::stars.access', ['star' => $this->star])
        ->assertSet('name', 'Cursor')
        ->assertDispatched('modal-show', name: 'new-token');

    $page->call('create')->assertHasNoErrors();

    expect($this->star->tokens()->sole()->name)->toBe('Cursor');
});

it('ignores a suggested token name that is not a plain name', function (mixed $suggested): void {
    Livewire::withQueryParams(['new_token' => $suggested])->test('pages::stars.access', ['star' => $this->star])
        ->assertSet('name', '')
        ->assertNotDispatched('modal-show');
})->with([
    'markup' => '<script>alert("token")</script>',
    'a line break' => "Cursor\nexport EVIL=1",
    'a trailing line break' => "Cursor\n",
    'a quote' => 'Cursor"',
    'blank' => ' ',
    'longer than a token name' => str_repeat('a', 101),
    'a list' => [['Cursor']],
]);

it('suggests no token name when the Star can have no more tokens, or no longer uses them', function (Closure $arrange): void {
    $arrange($this->star);

    Livewire::withQueryParams(['new_token' => 'Cursor'])->test('pages::stars.access', ['star' => $this->star])
        ->assertSet('name', '')
        ->assertNotDispatched('modal-show');
})->with([
    'at the tokens limit' => [function (Star $star): void {
        config(['nexus.limits.tokens_per_star' => 1]);
        StarToken::factory()->for($star)->create();
    }],
    'in signed-URL mode' => [fn (Star $star): bool => $star->forceFill(['access_mode' => StarAccessMode::SignedUrl])->save()],
]);

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
        ->assertSeeTextInOrder(['Access mode', 'Signed URL', 'Signed URL', 'Anyone who has this URL can use the Star', $this->star->signedUrl(), 'Rotate URL'])
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
            'OAuth', 'Clients send you to Nexus to sign in and approve them, so there is no secret to copy.',
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

describe('OAuth mode', function (): void {
    beforeEach(function (): void {
        $this->star->forceFill(['access_mode' => StarAccessMode::OAuth])->save();
    });

    it('lists the Star\'s connected apps with where they return to, when they were approved and last used', function (): void {
        $this->travelTo(now()->startOfDay());
        StarOAuthClient::factory()->for($this->star)->create([
            'client_id' => Client::factory()->asPublic()->create(['name' => 'Claude Code', 'redirect_uris' => ['http://localhost:54212/callback', 'http://127.0.0.1:54212/callback']])->id,
            'approved_at' => now()->subDays(3),
            'last_used_at' => now()->subHours(2),
        ]);
        StarOAuthClient::factory()->for($this->star)->create([
            'client_id' => Client::factory()->asPublic()->create(['name' => 'Cursor', 'redirect_uris' => ['cursor://anysphere.cursor-mcp/oauth/callback']])->id,
            'approved_at' => now()->subDay(),
        ]);

        $this->get(route('stars.access', $this->star))
            ->assertOk()
            ->assertSeeTextInOrder(['Access mode', 'OAuth', 'Connected apps'])
            ->assertSeeTextInOrder(['App', 'Returns to', 'Approved', 'Last used'])
            ->assertSeeTextInOrder(['Cursor', 'cursor://anysphere.cursor-mcp', '1 day ago', 'Never'])
            ->assertSeeTextInOrder(['Claude Code', 'localhost, 127.0.0.1', '3 days ago', '2 hours ago'])
            ->assertDontSeeText('No tokens yet');
    });

    it('lists neither clients waiting for approval, nor revoked ones, nor another Star\'s', function (): void {
        StarOAuthClient::factory()->for($this->star)->create(['client_id' => Client::factory()->asPublic()->create(['name' => 'Registered only'])->id]);
        StarOAuthClient::factory()->for($this->star)->approved()->create(['client_id' => Client::factory()->asPublic()->create(['name' => 'Revoked', 'revoked' => true])->id]);
        StarOAuthClient::factory()->approved()->create(['client_id' => Client::factory()->asPublic()->create(['name' => 'Someone else\'s'])->id]);

        $this->get(route('stars.access', $this->star))
            ->assertOk()
            ->assertSeeText('No connected apps yet')
            ->assertDontSeeText('Registered only')
            ->assertDontSeeText('Revoked')
            ->assertDontSeeText('Someone else\'s');
    });

    it('escapes the names clients register with', function (): void {
        StarOAuthClient::factory()->for($this->star)->approved()->create([
            'client_id' => Client::factory()->asPublic()->create(['name' => '<script>alert("app")</script>'])->id,
        ]);

        $this->get(route('stars.access', $this->star))
            ->assertOk()
            ->assertDontSee('<script>alert("app")</script>', escape: false);
    });

    it('revokes a connected app after confirming: its client, its access and refresh tokens', function (): void {
        $clientId = StarOAuthFlow::register($this->star, 'Claude');
        $tokens = StarOAuthFlow::signIn($this->user, $clientId);
        $otherClientId = StarOAuthFlow::register($this->star, 'Cursor');
        $otherTokens = StarOAuthFlow::signIn($this->user, $otherClientId);
        $app = StarOAuthClient::query()->where('client_id', $clientId)->sole();

        Livewire::test('pages::stars.access', ['star' => $this->star])
            ->assertSeeText('Revoke Claude?')
            ->call('revokeApp', $app->id)
            ->assertDispatched('toast-show', accessToast('Revoked Claude. It can no longer reach this Star, and has to be approved again to come back.'))
            ->assertDontSeeText('Revoke Claude?')
            ->assertSeeText('Cursor');

        expect(Client::query()->findOrFail($clientId)->revoked)->toBeTrue()
            ->and(Token::query()->where('client_id', $clientId)->where('revoked', false)->count())->toBe(0)
            ->and(RefreshToken::query()->where('revoked', false)->count())->toBe(1);
        StarClient::for($this->star)->withToken($tokens['access_token'])->connect()->assertUnauthorized();
        StarOAuthFlow::refresh($clientId, $tokens['refresh_token'])->assertUnauthorized();
        StarClient::for($this->star)->withToken($otherTokens['access_token'])->connect()->assertOk();
    });

    it('does not revoke another Star\'s app', function (): void {
        $otherApp = StarOAuthClient::factory()->for(Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth))->approved()->create();

        Livewire::test('pages::stars.access', ['star' => $this->star])
            ->call('revokeApp', $otherApp->id)
            ->assertNotFound();

        expect($otherApp->client?->fresh()?->revoked)->toBeFalse();
    });

    it('says how clients connect when the Star has no connected apps yet', function (): void {
        $this->get(route('stars.access', $this->star))
            ->assertSeeText('No connected apps yet')
            ->assertSeeText('When the client connects, it sends you to Nexus to approve it.')
            ->assertSee(route('stars.show', $this->star));
    });

    it('switches away from OAuth after confirming, revoking every client that registered with the Star', function (): void {
        $clientId = StarOAuthFlow::register($this->star, 'Claude');
        $tokens = StarOAuthFlow::signIn($this->user, $clientId);
        $waitingClientId = StarOAuthFlow::register($this->star, 'Waiting');

        Livewire::test('pages::stars.access', ['star' => $this->star])
            ->set('accessMode', 'token')
            ->assertSeeText('Switch to Bearer token?')
            ->assertSeeText('Its connected apps are revoked, so they stop reaching the Star at once, and switching back means approving each of them again.')
            ->call('changeAccessMode')
            ->assertHasNoErrors();

        expect(Client::query()->whereKey([$clientId, $waitingClientId])->pluck('revoked')->all())->toBe([true, true]);

        Livewire::test('pages::stars.access', ['star' => $this->star->refresh()])
            ->set('accessMode', 'oauth')
            ->call('changeAccessMode')
            ->assertDispatched('toast-show', accessToast('This Star now uses OAuth. Add its URL to a client, which sends you to Nexus to approve it.'))
            ->assertSeeText('No connected apps yet');

        StarClient::for($this->star->refresh())->withToken($tokens['access_token'])->connect()->assertUnauthorized();
        StarOAuthFlow::refresh($clientId, $tokens['refresh_token'])->assertUnauthorized();
    });
});

it('switches to OAuth after confirming, revoking the Star\'s tokens', function (): void {
    $plainToken = StarToken::generate();
    $token = StarToken::factory()->for($this->star)->plain($plainToken)->create();

    Livewire::test('pages::stars.access', ['star' => $this->star])
        ->set('accessMode', 'oauth')
        ->assertSeeText('Switch to OAuth?')
        ->assertSeeText('Its tokens are revoked, so clients using them stop reaching the Star at once.')
        ->call('changeAccessMode')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', accessToast('This Star now uses OAuth. Add its URL to a client, which sends you to Nexus to approve it.'))
        ->assertSeeText('Connected apps');

    expect($this->star->refresh()->access_mode)->toBe(StarAccessMode::OAuth);
    $this->assertModelMissing($token);
    StarClient::for($this->star)->withToken($plainToken)->connect()
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="nexus", resource_metadata="'.$this->star->protectedResourceMetadataUrl().'", scope="mcp:use", error="invalid_token"');
});
