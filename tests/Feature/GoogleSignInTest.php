<?php

declare(strict_types=1);

use App\Enums\IdentityProvider;
use App\Models\SignInIdentity;
use App\Models\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

beforeEach(function (): void {
    config(['services.google.client_id' => 'nexus-google-client-id', 'services.google.client_secret' => 'nexus-google-client-secret']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function fakeGoogleUser(array $attributes = []): void
{
    Socialite::fake('google', SocialiteUser::fake([
        'id' => '109876543210987654321',
        'name' => 'Mona Lisa',
        'email' => 'mona@gmail.com',
        'avatar' => 'https://lh3.googleusercontent.com/a/mona=s96-c',
        ...$attributes,
    ]));
}

/**
 * Answer the requests Socialite's real Google provider sends (the token
 * exchange, then the profile) with these responses in turn, and record them.
 * The provider talks to Google with its own Guzzle client, which the
 * `guzzle` option configures.
 *
 * @param  list<PsrResponse>  $responses
 * @return ArrayObject<int, array{request: RequestInterface}>
 */
function fakeGoogleEndpoints(array $responses): ArrayObject
{
    /** @var ArrayObject<int, array{request: RequestInterface}> $history */
    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    config(['services.google.guzzle' => ['handler' => $stack]]);

    return $history;
}

/**
 * @return list<PsrResponse>
 */
function googleApproves(): array
{
    return [
        new PsrResponse(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'access_token' => 'ya29.test-access-token',
            'expires_in' => 3599,
            'scope' => 'openid https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email',
            'token_type' => 'Bearer',
        ])),
        new PsrResponse(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'sub' => '109876543210987654321',
            'name' => 'Mona Lisa',
            'email' => 'mona@gmail.com',
            'email_verified' => true,
            'picture' => 'https://lh3.googleusercontent.com/a/mona=s96-c',
        ])),
    ];
}

/**
 * Open the route that sends the visitor to Google, and return the state it
 * sent along.
 */
function startGoogle(TestCase $test, string $route = 'auth.google'): string
{
    $location = (string) $test->get(route($route))->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return is_string($query['state'] ?? null) ? $query['state'] : '';
}

/**
 * Come back from Google with this query. Every real request builds its
 * Google provider afresh; the test app would reuse the one the previous
 * request built, along with that request's input, so it is forgotten first.
 *
 * @param  array<string, string>  $query
 * @return TestResponse<Response>
 */
function returnFromGoogle(TestCase $test, array $query): TestResponse
{
    Socialite::forgetDrivers();

    return $test->get(route('auth.google.callback', $query));
}

/**
 * Play another request giving the Google account to a user right after this
 * request looked it up and found nobody, and before it inserts its own.
 *
 * @param  Closure(): User  $owner
 */
function claimGoogleAccountMidRequest(Closure $owner): void
{
    $claimed = false;

    DB::listen(function (QueryExecuted $query) use (&$claimed, $owner): void {
        if (! $claimed && str_contains($query->sql, 'from "sign_in_identities" where "provider"')) {
            $claimed = true;

            SignInIdentity::factory()->for($owner())->create([
                'provider' => IdentityProvider::Google,
                'provider_user_id' => '109876543210987654321',
                'login' => 'mona@gmail.com',
            ]);
        }
    });
}

it('offers Google sign-in under GitHub when a Google client is configured', function (): void {
    $this->get(route('auth.sign-in'))
        ->assertOk()
        ->assertSeeTextInOrder(['Continue with GitHub', 'Continue with Google'])
        ->assertSee('href="'.route('auth.google').'"', escape: false);
});

it('sends the visitor to Google asking for their profile and email address, with the account chooser', function (): void {
    $location = $this->get(route('auth.google'))->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/auth?');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($query)
        ->client_id->toBe('nexus-google-client-id')
        ->redirect_uri->toBe(route('auth.google.callback'))
        ->scope->toBe('openid profile email')
        ->prompt->toBe('select_account')
        ->state->not->toBeEmpty();
});

it('hides Google sign-in when no Google client is configured', function (array $config): void {
    config($config);
    $user = User::factory()->signsInWithGitHub('octocat')->create();

    $this->get(route('auth.sign-in'))
        ->assertOk()
        ->assertSeeText('Continue with GitHub')
        ->assertDontSeeText('Continue with Google');

    $this->get(route('auth.google'))->assertRedirect(route('auth.sign-in'));
    $this->get(route('auth.sign-in'))->assertSeeText("Google sign-in isn't set up on this Nexus.");

    $this->actingAs($user);

    Livewire::test('pages::settings.index')->assertDontSeeText('Add Google');

    $this->get(route('settings.add-google'))->assertRedirect(route('settings.index'));
    $this->get(route('settings.index'))->assertSeeText("Google sign-in isn't set up on this Nexus.");
})->with([
    'no client' => [['services.google.client_id' => null, 'services.google.client_secret' => null]],
    'no secret' => [['services.google.client_secret' => '']],
]);

it('creates the account and its Google identity for a Google account nobody has', function (): void {
    fakeGoogleUser();

    $this->get(route('auth.google.callback'))->assertRedirect(route('stars.index'));

    $user = User::query()->sole();

    expect($user)
        ->name->toBe('Mona Lisa')
        ->email->toBe('mona@gmail.com')
        ->avatar_url->toBe('https://lh3.googleusercontent.com/a/mona=s96-c')
        ->github_id->toBeNull()
        ->and($user->signInIdentities()->sole())
        ->provider->toBe(IdentityProvider::Google)
        ->provider_user_id->toBe('109876543210987654321')
        ->login->toBe('mona@gmail.com');

    $this->assertAuthenticatedAs($user);
});

it('signs in to the account a simultaneous first Google sign-in just created, instead of failing', function (): void {
    $competing = null;
    claimGoogleAccountMidRequest(function () use (&$competing): User {
        return $competing = User::factory()->create(['name' => 'First tab']);
    });

    fakeGoogleUser();

    $this->get(route('auth.google.callback'))->assertRedirect(route('stars.index'));

    expect($competing)->toBeInstanceOf(User::class)
        ->and(User::query()->count())->toBe(1)
        ->and(SignInIdentity::query()->count())->toBe(1);

    $this->assertAuthenticatedAs($competing);
});

it('uses the start of the email address as the name when Google sends none', function (): void {
    fakeGoogleUser(['name' => null]);

    $this->get(route('auth.google.callback'));

    expect(User::query()->sole()->name)->toBe('mona');
});

it('finds a returning user by their Google identity even when the email address changed', function (): void {
    $user = User::factory()->signsInWithGitHub('octocat')->create(['name' => 'Mona Lisa Octocat', 'email' => 'octocat@github.com']);
    $identity = SignInIdentity::factory()->for($user)->create([
        'provider' => IdentityProvider::Google,
        'provider_user_id' => '109876543210987654321',
        'login' => 'old-address@gmail.com',
    ]);

    fakeGoogleUser();

    $this->get(route('auth.google.callback'))->assertRedirect(route('stars.index'));

    expect(User::query()->count())->toBe(1)
        ->and($identity->fresh()?->login)->toBe('mona@gmail.com')
        ->and($user->fresh())
        ->name->toBe('Mona Lisa Octocat')
        ->email->toBe('octocat@github.com');

    $this->assertAuthenticatedAs($user);
});

it('never signs in to another account that has the same email address', function (): void {
    $sameGitHubEmail = User::factory()->signsInWithGitHub('mona')->create(['email' => 'mona@gmail.com']);
    $sameEmailSignIn = User::factory()
        ->has(SignInIdentity::factory()->email('mona@gmail.com'), 'signInIdentities')
        ->create(['email' => 'mona@gmail.com']);

    fakeGoogleUser();

    $this->get(route('auth.google.callback'))->assertRedirect(route('stars.index'));

    $user = SignInIdentity::findFor(IdentityProvider::Google, '109876543210987654321')?->user;

    expect($user)->toBeInstanceOf(User::class)
        ->and($user?->is($sameGitHubEmail))->toBeFalse()
        ->and($user?->is($sameEmailSignIn))->toBeFalse()
        ->and($sameGitHubEmail->signInIdentities()->count())->toBe(1)
        ->and($sameEmailSignIn->signInIdentities()->count())->toBe(1);

    $this->assertAuthenticatedAs($user);
});

it('returns to the page the guest asked for after signing in with Google', function (): void {
    $this->get(route('settings.index'))->assertRedirect(route('auth.sign-in'));

    fakeGoogleUser();

    $this->get(route('auth.google.callback'))->assertRedirect(route('settings.index'));
});

it('returns to the sign-in page when the user cancels on Google', function (): void {
    fakeGoogleUser();

    $this->get(route('auth.google.callback', ['error' => 'access_denied', 'state' => 'abc']))
        ->assertRedirect(route('auth.sign-in'));

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);

    $this->get(route('auth.sign-in'))->assertSeeText('Google sign-in was cancelled. Sign in again whenever you like.');
});

it('returns to the sign-in page when the Google sign-in fails', function (Closure $failure): void {
    Socialite::fake('google', $failure);

    $this->get(route('auth.google.callback'))->assertRedirect(route('auth.sign-in'));

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);

    $this->get(route('auth.sign-in'))->assertSeeText("Google sign-in didn't complete. Please try again.");
})->with([
    'Google unreachable' => [fn () => throw new ConnectException(
        'Could not resolve host',
        new PsrRequest('POST', 'https://www.googleapis.com/oauth2/v4/token'),
    )],
    'no email address' => [fn (): SocialiteUser => SocialiteUser::fake(['id' => '109876543210987654321', 'email' => null])],
]);

it('signs in through Google\'s endpoints when Google returns the state Nexus sent', function (): void {
    $google = fakeGoogleEndpoints(googleApproves());

    $state = startGoogle($this);

    returnFromGoogle($this, ['state' => $state, 'code' => 'google-code'])->assertRedirect(route('stars.index'));

    $user = SignInIdentity::findFor(IdentityProvider::Google, '109876543210987654321')?->user;

    expect($user)->toBeInstanceOf(User::class)
        ->and($user?->email)->toBe('mona@gmail.com')
        ->and($google)->toHaveCount(2);

    parse_str((string) $google[0]['request']->getBody(), $tokenRequest);

    expect($tokenRequest)
        ->code->toBe('google-code')
        ->redirect_uri->toBe(route('auth.google.callback'));

    $this->assertAuthenticatedAs($user);
});

it('refuses a return from Google without the state Nexus sent, before contacting Google', function (bool $started, array $query): void {
    $google = fakeGoogleEndpoints(googleApproves());

    if ($started) {
        startGoogle($this);
    }

    returnFromGoogle($this, $query)->assertRedirect(route('auth.sign-in'));

    $this->assertGuest();
    expect(User::query()->count())->toBe(0)
        ->and($google)->toHaveCount(0);

    $this->get(route('auth.sign-in'))->assertSeeText("Google sign-in didn't complete. Please try again.");
})->with([
    'no state' => [true, ['code' => 'google-code']],
    'another state' => [true, ['state' => 'forged-state', 'code' => 'google-code']],
    'a sign-in never started here' => [false, ['state' => 'forged-state', 'code' => 'google-code']],
]);

it('accepts the state Nexus sent only once', function (): void {
    $google = fakeGoogleEndpoints([new PsrResponse(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}')]);

    $state = startGoogle($this);

    returnFromGoogle($this, ['state' => $state, 'code' => 'google-code'])->assertRedirect(route('auth.sign-in'));
    returnFromGoogle($this, ['state' => $state, 'code' => 'google-code'])->assertRedirect(route('auth.sign-in'));

    $this->assertGuest();
    expect(User::query()->count())->toBe(0)
        ->and($google)->toHaveCount(1);
});

it('sends a signed-in user who opens the Google sign-in to the app', function (): void {
    $this->actingAs(User::factory()->signsInWithGitHub('octocat')->create());

    $this->get(route('auth.google'))->assertRedirect(route('stars.index'));
});

it('sends a signed-in user who arrives from Google without adding it to the app, adding nothing', function (): void {
    $user = User::factory()->signsInWithGitHub('octocat')->create();
    $this->actingAs($user);

    fakeGoogleUser();

    $this->get(route('auth.google.callback'))->assertRedirect(route('stars.index'));

    expect(SignInIdentity::query()->where('provider', IdentityProvider::Google)->exists())->toBeFalse();
    $this->assertAuthenticatedAs($user);
});

it('offers to add Google in Settings when a Google client is configured', function (): void {
    $this->actingAs(User::factory()->signsInWithGitHub('octocat')->create());

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSeeTextInOrder(['Sign-in methods', 'Add Google'])
        ->assertSee('href="'.route('settings.add-google').'"', escape: false);
});

it('adds the Google account to the signed-in user after Google approves', function (): void {
    $user = User::factory()->signsInWithGitHub('octocat')->create(['name' => 'Mona Lisa Octocat']);
    $this->actingAs($user);

    fakeGoogleUser();

    $this->get(route('settings.add-google'))->assertRedirect('https://socialite.fake/google/authorize');
    $this->get(route('auth.google.callback'))->assertRedirect(route('settings.index'));

    expect(User::query()->count())->toBe(1)
        ->and($user->signInIdentities()->where('provider', IdentityProvider::Google)->sole())
        ->provider_user_id->toBe('109876543210987654321')
        ->login->toBe('mona@gmail.com')
        ->and($user->fresh()?->name)->toBe('Mona Lisa Octocat');

    $this->assertAuthenticatedAs($user);

    $this->get(route('settings.index'))
        ->assertSeeText('Google added. You can now sign in with mona@gmail.com.')
        ->assertSeeTextInOrder(['Sign-in methods', 'GitHub', '@octocat', 'Google', 'mona@gmail.com']);
});

it('refuses to add a Google account that already signs in to another user', function (): void {
    $other = User::factory()->create();
    $identity = SignInIdentity::factory()->for($other)->create([
        'provider' => IdentityProvider::Google,
        'provider_user_id' => '109876543210987654321',
        'login' => 'mona@gmail.com',
    ]);
    $user = User::factory()->signsInWithGitHub('octocat')->create();
    $this->actingAs($user);

    fakeGoogleUser();

    $this->get(route('settings.add-google'));
    $this->get(route('auth.google.callback'))->assertRedirect(route('settings.index'));

    expect($identity->fresh()?->user_id)->toBe($other->id)
        ->and($user->signInIdentities()->pluck('provider')->all())->toBe([IdentityProvider::GitHub]);

    $this->assertAuthenticatedAs($user);

    $this->get(route('settings.index'))
        ->assertSeeText('The Google account mona@gmail.com already signs in to another Nexus account. To add it here, sign in with it and remove it from that account first.');
});

it('refuses to add a Google account another user claims while it is being added', function (): void {
    $user = User::factory()->signsInWithGitHub('octocat')->create();
    $this->actingAs($user);
    $other = User::factory()->create();

    fakeGoogleUser();

    $this->get(route('settings.add-google'));
    claimGoogleAccountMidRequest(fn (): User => $other);
    $this->get(route('auth.google.callback'))->assertRedirect(route('settings.index'));

    expect(SignInIdentity::findFor(IdentityProvider::Google, '109876543210987654321')?->user_id)->toBe($other->id)
        ->and($user->signInIdentities()->count())->toBe(1);

    $this->get(route('settings.index'))->assertSeeText('already signs in to another Nexus account');
});

it('says when the Google account is already one of the user\'s sign-in methods', function (): void {
    $user = User::factory()->signsInWithGitHub('octocat')->create();
    $identity = SignInIdentity::factory()->for($user)->create([
        'provider' => IdentityProvider::Google,
        'provider_user_id' => '109876543210987654321',
        'login' => 'old-address@gmail.com',
    ]);
    $this->actingAs($user);

    fakeGoogleUser();

    $this->get(route('settings.add-google'));
    $this->get(route('auth.google.callback'))->assertRedirect(route('settings.index'));

    expect($user->signInIdentities()->count())->toBe(2)
        ->and($identity->fresh()?->login)->toBe('mona@gmail.com');

    $this->get(route('settings.index'))->assertSeeText('mona@gmail.com is already one of your sign-in methods.');
});

it('returns to Settings without adding Google when the user cancels on Google', function (): void {
    $user = User::factory()->signsInWithGitHub('octocat')->create();
    $this->actingAs($user);

    fakeGoogleUser();

    $this->get(route('settings.add-google'));
    $this->get(route('auth.google.callback', ['error' => 'access_denied', 'state' => 'abc']))->assertRedirect(route('settings.index'));

    expect($user->signInIdentities()->count())->toBe(1);
    $this->get(route('settings.index'))->assertSeeText('Adding Google was cancelled. Add it again whenever you like.');
});

it('never adds a Google account from a return without the state Nexus sent, and contacts Google for none', function (): void {
    $user = User::factory()->signsInWithGitHub('octocat')->create();
    $this->actingAs($user);
    $google = fakeGoogleEndpoints(googleApproves());

    startGoogle($this, 'settings.add-google');

    returnFromGoogle($this, ['state' => 'forged-state', 'code' => 'google-code'])->assertRedirect(route('settings.index'));

    expect($user->signInIdentities()->count())->toBe(1)
        ->and(SignInIdentity::query()->where('provider', IdentityProvider::Google)->exists())->toBeFalse()
        ->and($google)->toHaveCount(0);
    $this->assertAuthenticatedAs($user);
    $this->get(route('settings.index'))->assertSeeText("Adding Google didn't complete. Please try again.");
});

it('only lets a signed-in user add Google', function (): void {
    $this->get(route('settings.add-google'))->assertRedirect(route('auth.sign-in'));
});
