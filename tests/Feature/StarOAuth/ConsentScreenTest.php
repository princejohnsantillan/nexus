<?php

declare(strict_types=1);

use App\Actions\ChangeStarAccessMode;
use App\Enums\StarAccessMode;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\StarOAuthClient;
use App\Models\User;
use Illuminate\Support\Uri;
use Illuminate\Testing\TestResponse;
use Tests\Support\StarClient;
use Tests\Support\StarOAuthFlow;

/**
 * The method each of the consent screen's forms sends to /oauth/authorize:
 * DELETE denies, POST approves.
 *
 * @return list<string>
 */
function consentForms(TestResponse $response): array
{
    preg_match_all('/<form method="POST" action="[^"]*\/oauth\/authorize">(.*?)<\/form>/s', (string) $response->getContent(), $forms);

    return array_map(fn (string $form): string => str_contains($form, 'name="_method" value="DELETE"') ? 'DELETE' : 'POST', $forms[1]);
}

beforeEach(function (): void {
    $this->owner = User::factory()->create(['name' => 'Ada Lovelace', 'github_login' => 'ada']);
    $wiki = Connection::factory()->for($this->owner)->connected()->create(['handle' => 'wiki']);
    ConnectionTool::factory()->for($wiki)->count(2)->sequence(['name' => 'search', 'read_only' => true], ['name' => 'fetch', 'read_only' => true])->create();
    $this->star = Star::factory()->for($this->owner)->including($wiki)->withAccessMode(StarAccessMode::OAuth)->create(['name' => 'Work']);
    $this->clientId = StarOAuthFlow::register($this->star, 'Claude');
});

it('names the client, where it returns to, the Star and the signed-in user', function (): void {
    StarOAuthFlow::consent($this->owner, $this->clientId)
        ->assertOk()
        ->assertSeeHtml('<title>Approve access · Nexus</title>')
        ->assertSeeText('Allow Claude to use your Star “Work”?')
        ->assertSeeText('It will be able to call the 2 tools switched on in this Star, as you, until you revoke it on the Star\'s Access page.')
        ->assertSeeTextInOrder(['App', 'Claude', 'Returns to', 'claude.ai', 'Star', 'Work', 'Signed in as', 'Ada Lovelace (@ada)'])
        ->assertSeeText('Deny')
        ->assertSeeText('Approve');

    expect(consentForms(StarOAuthFlow::consent($this->owner, $this->clientId)))->toBe(['DELETE', 'POST']);
});

it('escapes the name a client registers with', function (): void {
    $clientId = StarOAuthFlow::register($this->star, '<script>alert(1)</script>');

    StarOAuthFlow::consent($this->owner, $clientId)
        ->assertOk()
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml(e('<script>alert(1)</script>'));
});

it('sends a guest to sign in first, remembering to come back to the consent screen', function (): void {
    $authorizeUrl = StarOAuthFlow::authorizeUrl($this->clientId);

    $this->get($authorizeUrl)->assertRedirect(route('auth.sign-in'));

    $intended = Uri::of((string) session('url.intended'));

    expect($intended->path())->toBe('oauth/authorize')
        ->and($intended->query()->all())->toEqual(Uri::of($authorizeUrl)->query()->all());
});

it('approves the client for the Star\'s owner and sends the code back to the client', function (): void {
    $this->travelTo(now()->startOfSecond());
    StarOAuthFlow::consent($this->owner, $this->clientId)->assertOk();

    $callback = Uri::of((string) StarOAuthFlow::approve()->assertRedirect()->headers->get('Location'));

    expect($callback->withQuery([])->value())->toStartWith(StarOAuthFlow::REDIRECT_URI)
        ->and($callback->query()->get('state'))->toBe('state-1')
        ->and($callback->query()->get('code'))->toBeString()->not->toBeEmpty()
        ->and(StarOAuthClient::query()->sole()->approved_at?->toIso8601String())->toBe(now()->toIso8601String());
});

it('sends a denial back to the client, approving nothing', function (): void {
    StarOAuthFlow::consent($this->owner, $this->clientId)->assertOk();

    $location = (string) $this->delete(route('passport.authorizations.deny'), ['auth_token' => session('authToken')])->assertRedirect()->headers->get('Location');

    expect(Uri::of($location)->query()->get('error'))->toBe('access_denied')
        ->and(StarOAuthClient::query()->sole()->approved_at)->toBeNull();
});

it('offers someone else no way to approve a client for the owner\'s Star, and does not name it', function (): void {
    $intruder = User::factory()->create(['name' => 'Mallory']);

    $response = StarOAuthFlow::consent($intruder, $this->clientId)
        ->assertOk()
        ->assertSeeText('Claude isn\'t asking for one of your Stars')
        ->assertSeeText('Approving it wouldn\'t give it access, so you can only deny it.')
        ->assertSeeText('Mallory')
        ->assertDontSeeText('Work')
        ->assertSeeText('Deny');

    expect(consentForms($response))->toBe(['DELETE']);
});

it('refuses an approval someone else sends anyway, so no token is issued', function (): void {
    $intruder = User::factory()->create();

    StarOAuthFlow::consent($intruder, $this->clientId)->assertOk();
    StarOAuthFlow::approve()->assertForbidden();

    expect(StarOAuthClient::query()->sole()->approved_at)->toBeNull()
        ->and($this->star->connectedApps()->count())->toBe(0);
});

it('refuses a client revoked when the Star left OAuth mode, even after it switches back', function (): void {
    resolve(ChangeStarAccessMode::class)->handle($this->star, StarAccessMode::SignedUrl);
    resolve(ChangeStarAccessMode::class)->handle($this->star, StarAccessMode::OAuth);

    $this->actingAs($this->owner)->get(StarOAuthFlow::authorizeUrl($this->clientId))->assertUnauthorized();
});

it('refuses an approval for a Star that switched away from OAuth while the screen was open', function (): void {
    StarOAuthFlow::consent($this->owner, $this->clientId)->assertOk()->assertSeeText('Approve');
    $this->star->forceFill(['access_mode' => StarAccessMode::Token])->save();

    StarOAuthFlow::approve()->assertForbidden();
});

it('refuses an approval with a wrong auth token', function (): void {
    StarOAuthFlow::consent($this->owner, $this->clientId)->assertOk();

    $response = $this->post(route('passport.authorizations.approve'), ['auth_token' => 'guessed']);

    expect($response->status())->toBeGreaterThanOrEqual(400)
        ->and(StarOAuthClient::query()->sole()->approved_at)->toBeNull();
});

it('requires a signed-in user to approve or deny', function (string $method): void {
    $this->call($method, '/oauth/authorize', ['auth_token' => 'x'])->assertRedirect(route('auth.sign-in'));
})->with(['POST', 'DELETE']);

it('gives a token only for the Star the client registered with', function (): void {
    $tokens = StarOAuthFlow::signIn($this->owner, $this->clientId);

    StarClient::for($this->star)->withToken($tokens['access_token'])->connect()->assertOk();
});
