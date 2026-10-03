<?php

declare(strict_types=1);

use App\Models\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
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

it('creates the account on the first sign-in', function (): void {
    fakeGitHubUser();

    $this->get(route('auth.github.callback'))->assertRedirect(route('stars.index'));

    $user = User::query()->sole();

    expect($user)
        ->github_id->toBe(583231)
        ->github_login->toBe('octocat')
        ->name->toBe('Mona Lisa Octocat')
        ->email->toBe('octocat@github.com')
        ->avatar_url->toBe('https://avatars.githubusercontent.com/u/583231?v=4');

    $this->assertAuthenticatedAs($user);
});

it('matches a returning user by GitHub id and refreshes their profile', function (): void {
    $user = User::factory()->create([
        'github_id' => 583231,
        'github_login' => 'old-login',
        'name' => 'Old Name',
        'email' => 'old@example.com',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/583231?v=1',
    ]);

    fakeGitHubUser();

    $this->get(route('auth.github.callback'))->assertRedirect(route('stars.index'));

    expect(User::query()->count())->toBe(1);

    expect($user->fresh())
        ->github_login->toBe('octocat')
        ->name->toBe('Mona Lisa Octocat')
        ->email->toBe('octocat@github.com')
        ->avatar_url->toBe('https://avatars.githubusercontent.com/u/583231?v=4');

    $this->assertAuthenticatedAs($user);
});

it('never signs in to another account that has the same login or email', function (): void {
    $other = User::factory()->create([
        'github_id' => 1,
        'github_login' => 'octocat',
        'email' => 'octocat@github.com',
    ]);

    fakeGitHubUser();

    $this->get(route('auth.github.callback'))->assertRedirect(route('stars.index'));

    $user = User::query()->where('github_id', 583231)->sole();

    expect($user->is($other))->toBeFalse();

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
