<?php

declare(strict_types=1);

use App\Enums\IdentityProvider;
use App\Models\SignInIdentity;
use App\Models\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * @param  array<string, mixed>  $attributes
 */
function fakeGitHubUser(array $attributes = []): void
{
    Socialite::fake('github', SocialiteUser::fake([
        'id' => 583231,
        'nickname' => 'octocat',
        'name' => 'Mona Lisa Octocat',
        'email' => 'octocat@github.com',
        'avatar' => 'https://avatars.githubusercontent.com/u/583231?v=4',
        ...$attributes,
    ]));
}

it('sends the visitor to GitHub asking for their profile and email addresses', function (): void {
    config(['services.github.client_id' => 'nexus-client-id']);

    $location = $this->get(route('auth.github'))->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://github.com/login/oauth/authorize?');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($query)
        ->client_id->toBe('nexus-client-id')
        ->redirect_uri->toBe(route('auth.github.callback'))
        ->scope->toBe('read:user,user:email')
        ->state->not->toBeEmpty();
});

it('explains that GitHub sign-in is not set up when there is no GitHub OAuth app', function (): void {
    config(['services.github.client_id' => null]);

    $this->get(route('auth.github'))->assertRedirect(route('auth.sign-in'));

    $this->get(route('auth.sign-in'))->assertSeeText("GitHub sign-in isn't set up on this Nexus yet.");
});

it('creates the account and its GitHub identity on the first sign-in', function (): void {
    fakeGitHubUser();

    $this->get(route('auth.github.callback'))->assertRedirect(route('stars.index'));

    $user = User::query()->sole();

    expect($user)
        ->name->toBe('Mona Lisa Octocat')
        ->email->toBe('octocat@github.com')
        ->avatar_url->toBe('https://avatars.githubusercontent.com/u/583231?v=4')
        ->and($user->signInIdentities()->sole())
        ->provider->toBe(IdentityProvider::GitHub)
        ->provider_user_id->toBe('583231')
        ->login->toBe('octocat');

    $this->assertAuthenticatedAs($user);
});

it('keeps the old GitHub columns up to date for code that still reads them', function (): void {
    fakeGitHubUser();

    $this->get(route('auth.github.callback'));

    expect(User::query()->sole())
        ->github_id->toBe(583231)
        ->github_login->toBe('octocat');
});

it('finds a returning user by their GitHub identity and refreshes their profile and login', function (): void {
    $user = User::factory()->signsInWithGitHub('old-login', githubId: 583231)->create([
        'name' => 'Old Name',
        'email' => 'old@example.com',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/583231?v=1',
    ]);

    fakeGitHubUser();

    $this->get(route('auth.github.callback'))->assertRedirect(route('stars.index'));

    expect(User::query()->count())->toBe(1);

    expect($user->fresh())
        ->name->toBe('Mona Lisa Octocat')
        ->email->toBe('octocat@github.com')
        ->avatar_url->toBe('https://avatars.githubusercontent.com/u/583231?v=4');

    expect($user->signInIdentities()->sole())
        ->provider_user_id->toBe('583231')
        ->login->toBe('octocat');

    $this->assertAuthenticatedAs($user);
});

it('gives a user who signed up before identities existed their GitHub identity', function (): void {
    $user = User::factory()->create(['github_id' => 583231, 'github_login' => 'octocat']);

    fakeGitHubUser();

    $this->get(route('auth.github.callback'))->assertRedirect(route('stars.index'));

    expect(User::query()->count())->toBe(1)
        ->and($user->signInIdentities()->sole())
        ->provider->toBe(IdentityProvider::GitHub)
        ->provider_user_id->toBe('583231');

    $this->assertAuthenticatedAs($user);
});

it('signs in to the account a simultaneous first sign-in just created, instead of failing', function (): void {
    $competing = null;

    // The other sign-in creates the account after this one looked for it and before it inserts its own.
    DB::listen(function (QueryExecuted $query) use (&$competing): void {
        if ($competing === null && str_contains($query->sql, 'from "users" where "github_id"')) {
            $competing = User::factory()->signsInWithGitHub('octocat', githubId: 583231)->create(['github_id' => 583231, 'name' => 'First tab']);
        }
    });

    fakeGitHubUser();

    $this->get(route('auth.github.callback'))->assertRedirect(route('stars.index'));

    expect($competing)->toBeInstanceOf(User::class)
        ->and(User::query()->count())->toBe(1)
        ->and(SignInIdentity::query()->count())->toBe(1)
        ->and($competing->fresh()?->name)->toBe('Mona Lisa Octocat');

    $this->assertAuthenticatedAs($competing);
});

it('never signs in to another account that has the same login or email', function (): void {
    $sameLogin = User::factory()->signsInWithGitHub('octocat', githubId: 1)->create();
    $sameEmail = User::factory()
        ->has(SignInIdentity::factory()->email('octocat@github.com'), 'signInIdentities')
        ->create(['email' => 'octocat@github.com']);

    fakeGitHubUser();

    $this->get(route('auth.github.callback'))->assertRedirect(route('stars.index'));

    $user = SignInIdentity::findFor(IdentityProvider::GitHub, '583231')?->user;

    expect($user)->toBeInstanceOf(User::class)
        ->and($user?->is($sameLogin))->toBeFalse()
        ->and($user?->is($sameEmail))->toBeFalse()
        ->and($sameLogin->signInIdentities()->pluck('login')->all())->toBe(['octocat'])
        ->and($sameEmail->signInIdentities()->count())->toBe(1);

    $this->assertAuthenticatedAs($user);
});

it('signs in a user whose GitHub email is hidden', function (): void {
    fakeGitHubUser(['email' => null]);

    $this->get(route('auth.github.callback'))->assertRedirect(route('stars.index'));

    $user = User::query()->sole();

    expect($user->email)->toBeNull();

    $this->assertAuthenticatedAs($user);
});

it('uses the GitHub login as the name when the profile has none', function (): void {
    fakeGitHubUser(['name' => null]);

    $this->get(route('auth.github.callback'));

    expect(User::query()->sole()->name)->toBe('octocat');
});

it('returns to the page the guest asked for after signing in', function (): void {
    $this->get(route('settings.index'))->assertRedirect(route('auth.sign-in'));

    fakeGitHubUser();

    $this->get(route('auth.github.callback'))->assertRedirect(route('settings.index'));
});

it('returns to the sign-in page when the user cancels on GitHub', function (): void {
    fakeGitHubUser();

    $this->get(route('auth.github.callback', ['error' => 'access_denied', 'state' => 'abc']))
        ->assertRedirect(route('auth.sign-in'));

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);

    $this->get(route('auth.sign-in'))->assertSeeText('GitHub sign-in was cancelled. Sign in again whenever you like.');
});

it('returns to the sign-in page when the sign-in fails', function (Throwable $failure): void {
    Socialite::fake('github', fn () => throw $failure);

    $this->get(route('auth.github.callback'))->assertRedirect(route('auth.sign-in'));

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);

    $this->get(route('auth.sign-in'))->assertSeeText("GitHub sign-in didn't complete. Please try again.");
})->with([
    'state mismatch' => fn (): Throwable => new InvalidStateException,
    'GitHub unreachable' => fn (): Throwable => new ConnectException(
        'Could not resolve host',
        new PsrRequest('POST', 'https://github.com/login/oauth/access_token'),
    ),
]);

it('sends a signed-in user who opens the GitHub sign-in to the app', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('auth.github'))->assertRedirect(route('stars.index'));
});
