<?php

declare(strict_types=1);

use App\Actions\ChangeStarAccessMode;
use App\Enums\NewToolPolicy;
use App\Enums\StarAccessMode;
use App\Models\Connection;
use App\Models\ConnectionTool;
use App\Models\Star;
use App\Models\StarOAuthClient;
use App\Models\User;
use Illuminate\Support\Uri;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
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

/**
 * Where the consent screen's "Not you?" form posts to.
 */
function notYouAction(TestResponse $response): string
{
    preg_match('/<form method="POST" action="([^"]*\/oauth\/authorize\/switch-account[^"]*)">/', (string) $response->getContent(), $form);

    return html_entity_decode($form[1] ?? '');
}

/**
 * Expect a URL to lead to the same page as another, with the same query in any order.
 */
function expectSameAddress(string $url, string $expected): void
{
    expect(Uri::of($url)->replaceQuery([])->value())->toBe(Uri::of($expected)->replaceQuery([])->value())
        ->and(Uri::of($url)->query()->all())->toEqual(Uri::of($expected)->query()->all());
}

beforeEach(function (): void {
    $this->owner = User::factory()->signsInWithGitHub('ada')->create(['name' => 'Ada Lovelace']);
    $wiki = Connection::factory()->for($this->owner)->connected()->create(['handle' => 'wiki']);
    ConnectionTool::factory()->for($wiki)->count(2)->sequence(['name' => 'search', 'read_only' => true], ['name' => 'fetch', 'read_only' => true])->create();
    $this->star = Star::factory()->for($this->owner)->including($wiki)->withAccessMode(StarAccessMode::OAuth)->create(['name' => 'Work']);
    $this->clientId = StarOAuthFlow::register($this->star, 'Claude');
});

it('shows Nexus and the client, what the client can do, where it returns to and who is signed in', function (): void {
    $response = StarOAuthFlow::consent($this->owner, $this->clientId)
        ->assertOk()
        ->assertSeeHtml('<title>Approve access · Nexus</title>')
        ->assertSeeTextInOrder([
            'Allow Claude to use your Star “Work”?',
            'Signed in as', 'Ada Lovelace', 'Not you?',
            'What Claude can do',
            'Only approve it if you just added this Star to Claude yourself.',
            'Call the 2 tools switched on in this Star',
            'As you',
            'Until you revoke it', 'Revoke it any time on the Star\'s Access page.',
            'Authorizing will redirect to', 'claude.ai',
            'Deny', 'Approve',
        ]);

    expect(consentForms($response))->toBe(['DELETE', 'POST']);
    expectSameAddress(notYouAction($response), route('oauth.switch-account', Uri::of(StarOAuthFlow::authorizeUrl($this->clientId))->query()->all()));
});

it('says the client can call nothing until a tool is switched on in the Star', function (): void {
    $this->star->update(['new_tool_policy' => NewToolPolicy::None]);

    StarOAuthFlow::consent($this->owner, $this->clientId)
        ->assertOk()
        ->assertSeeText('Call the tools you switch on in this Star')
        ->assertSeeText('None are on yet, so it can\'t call anything until you switch some on.');
});

it('names only the host this request returns to when the client registered several', function (string $redirectUri, string $host, string $otherHost): void {
    $clientId = (string) StarOAuthFlow::registering($this->star, [
        'client_name' => 'Claude',
        'redirect_uris' => ['https://example.com/callback', 'http://127.0.0.1:33418/callback'],
    ])->assertCreated()->json('client_id');

    $this->actingAs($this->owner)->get(StarOAuthFlow::authorizeUrl($clientId, redirectUri: $redirectUri))
        ->assertOk()
        ->assertSeeTextInOrder(['Authorizing will redirect to', $host])
        ->assertDontSeeText($otherHost);

    expect((string) StarOAuthFlow::approve()->assertRedirect()->headers->get('Location'))->toStartWith($redirectUri.'?');
})->with([
    'the first' => ['https://example.com/callback', 'example.com', '127.0.0.1'],
    'the second' => ['http://127.0.0.1:33418/callback', '127.0.0.1', 'example.com'],
]);

it('names the client\'s only redirect host when the request names none', function (): void {
    $this->actingAs($this->owner)->get(Uri::of(StarOAuthFlow::authorizeUrl($this->clientId))->withoutQuery(['redirect_uri'])->value())
        ->assertOk()
        ->assertSeeTextInOrder(['Authorizing will redirect to', 'claude.ai']);
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
        ->assertSeeTextInOrder(['Signed in as', 'Mallory', 'Not you?'])
        ->assertDontSeeText('Work')
        ->assertSeeText('Deny');

    expect(consentForms($response))->toBe(['DELETE']);
});

it('signs the user out on "Not you?" and brings them back to the same consent screen once they sign in again', function (): void {
    $intruder = User::factory()->create(['name' => 'Mallory']);
    $authorizeUrl = StarOAuthFlow::authorizeUrl($this->clientId);

    $consent = $this->actingAs($intruder)->get($authorizeUrl)->assertOk()->assertSeeText('Claude isn\'t asking for one of your Stars');

    expectSameAddress((string) $this->post(notYouAction($consent))->assertRedirect()->headers->get('Location'), $authorizeUrl);
    $this->assertGuest();

    $this->get($authorizeUrl)->assertRedirect();

    Socialite::fake('github', SocialiteUser::fake(['id' => $this->owner->github_id, 'nickname' => 'ada', 'name' => 'Ada Lovelace']));

    expectSameAddress((string) $this->get(route('auth.github.callback'))->assertRedirect()->headers->get('Location'), $authorizeUrl);
    $this->assertAuthenticatedAs($this->owner);

    $this->get($authorizeUrl)
        ->assertOk()
        ->assertSeeText('Allow Claude to use your Star “Work”?')
        ->assertSeeTextInOrder(['Signed in as', 'Ada Lovelace']);
});

it('only ever sends "Not you?" back to the consent screen', function (): void {
    $location = (string) $this->actingAs($this->owner)
        ->post(route('oauth.switch-account', ['next' => 'https://evil.example']))
        ->assertRedirect()->headers->get('Location');

    expectSameAddress($location, route('passport.authorizations.authorize', ['next' => 'https://evil.example']));
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
