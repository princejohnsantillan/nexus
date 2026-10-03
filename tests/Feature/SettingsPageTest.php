<?php

declare(strict_types=1);

use App\Encryption\SecretCipher;
use App\Enums\IdentityProvider;
use App\Enums\StarAccessMode;
use App\Models\DataKey;
use App\Models\SignInIdentity;
use App\Models\Star;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Tests\Support\StarOAuthFlow;

beforeEach(function (): void {
    $this->user = User::factory()->signsInWithGitHub('octocat')->create([
        'name' => 'Mona Lisa Octocat',
        'email' => 'octocat@github.com',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/583231?v=4',
    ]);

    $this->actingAs($this->user);
});

it('shows the GitHub profile, the sign-in methods and the appearance switch', function (): void {
    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSee('<title>Settings · Nexus</title>', escape: false)
        ->assertSee('https://avatars.githubusercontent.com/u/583231?v=4')
        ->assertSeeTextInOrder(['Profile', 'Mona Lisa Octocat', '@octocat', 'Name', 'Mona Lisa Octocat', 'Email', 'octocat@github.com'])
        ->assertSeeTextInOrder(['Sign-in methods', 'GitHub', '@octocat'])
        ->assertSeeTextInOrder(['Appearance', 'Light', 'Dark', 'System']);
});

it('lists every sign-in method of the user, and only theirs', function (): void {
    SignInIdentity::factory()->for($this->user)->google('mona@gmail.com')->create();
    SignInIdentity::factory()->for($this->user)->email('mona@example.com')->create();
    SignInIdentity::factory()->gitHub('hubot')->create();

    Livewire::test('pages::settings.index')
        ->assertSeeTextInOrder(['Sign-in methods', 'GitHub', '@octocat', 'Google', 'mona@gmail.com', 'Email', 'mona@example.com'])
        ->assertDontSeeText('hubot');
});

it('says when GitHub does not share the email', function (): void {
    $this->user->update(['email' => null]);

    Livewire::test('pages::settings.index')
        ->assertSeeText('Not shared by GitHub');
});

it('escapes the profile GitHub sends', function (): void {
    $this->user->update(['name' => '<script>alert("name")</script>']);

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertDontSee('<script>alert("name")</script>', escape: false);
});

it('deletes the account and signs the user out after they type their GitHub login', function (): void {
    $otherUser = User::factory()->signsInWithGitHub('hubot')->create();

    Livewire::test('pages::settings.index')
        ->set('confirmation', 'octocat')
        ->call('deleteAccount')
        ->assertHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertModelMissing($this->user);
    $this->assertModelExists($otherUser);
    $this->assertGuest();

    $this->get(route('home'))->assertOk()->assertSeeText('Your account and everything in it were deleted.');
});

it('deletes the user\'s sign-in identities with the account, so nobody signs in to it again', function (): void {
    SignInIdentity::factory()->for($this->user)->email('mona@example.com')->create();
    $otherUser = User::factory()->signsInWithGitHub('hubot')->create();

    Livewire::test('pages::settings.index')
        ->set('confirmation', 'octocat')
        ->call('deleteAccount')
        ->assertHasNoErrors();

    expect(SignInIdentity::query()->pluck('user_id')->all())->toBe([$otherUser->id]);
});

it('asks a user without a GitHub login to type their email address, or else their name', function (Closure $makeUser, string $confirmation, string $label): void {
    $user = $makeUser();
    $this->actingAs($user);

    Livewire::test('pages::settings.index')
        ->assertSeeText($label)
        ->set('confirmation', $confirmation)
        ->call('deleteAccount')
        ->assertHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertModelMissing($user);
    $this->assertModelExists($this->user);
})->with([
    'an email sign-in' => [
        fn (): User => User::factory()->has(SignInIdentity::factory()->email('ada@example.com'), 'signInIdentities')->create(['email' => 'ada@example.com']),
        'ada@example.com',
        'Type your email address, ada@example.com, to confirm',
    ],
    'no email either' => [
        fn (): User => User::factory()->withHiddenEmail()->create(['name' => 'Ada Lovelace']),
        'Ada Lovelace',
        'Type your name, Ada Lovelace, to confirm',
    ],
]);

it('deletes the user\'s data key with the account, so the live database can no longer decrypt their secrets', function (): void {
    $otherUser = User::factory()->create();
    $ciphertext = resolve(SecretCipher::class)->encrypt($this->user->id, ['token' => 'sk-live-123']);
    resolve(SecretCipher::class)->encrypt($otherUser->id, ['token' => 'theirs']);

    Livewire::test('pages::settings.index')
        ->set('confirmation', 'octocat')
        ->call('deleteAccount');

    expect(DataKey::query()->pluck('user_id')->all())->toBe([$otherUser->id]);

    app()->forgetScopedInstances();
    expect(fn (): array => resolve(SecretCipher::class)->decrypt($this->user->id, $ciphertext))
        ->toThrow(DecryptException::class);
});

it('revokes the user\'s OAuth clients and every token issued to them with the account', function (): void {
    $star = Star::factory()->for($this->user)->withAccessMode(StarAccessMode::OAuth)->create();
    $clientId = StarOAuthFlow::register($star);
    $tokens = StarOAuthFlow::signIn($this->user, $clientId);
    $otherUser = User::factory()->create();
    $otherClientId = StarOAuthFlow::register(Star::factory()->for($otherUser)->withAccessMode(StarAccessMode::OAuth)->create());
    StarOAuthFlow::signIn($otherUser, $otherClientId);
    $this->actingAs($this->user);

    Livewire::test('pages::settings.index')
        ->set('confirmation', 'octocat')
        ->call('deleteAccount')
        ->assertHasNoErrors();

    expect(Client::query()->findOrFail($clientId)->revoked)->toBeTrue()
        ->and(Token::query()->where('user_id', $this->user->id)->pluck('revoked')->all())->toBe([true])
        ->and(RefreshToken::query()->where('revoked', true)->count())->toBe(1)
        ->and(Client::query()->findOrFail($otherClientId)->revoked)->toBeFalse()
        ->and(Token::query()->where('user_id', $otherUser->id)->pluck('revoked')->all())->toBe([false]);
    StarOAuthFlow::refresh($clientId, $tokens['refresh_token'])->assertUnauthorized();
});

it('keeps the account when the confirmation does not match the GitHub login', function (string $confirmation, string $message): void {
    Livewire::test('pages::settings.index')
        ->set('confirmation', $confirmation)
        ->call('deleteAccount')
        ->assertHasErrors('confirmation')
        ->assertSeeText($message)
        ->assertNoRedirect();

    $this->assertModelExists($this->user);
    $this->assertAuthenticatedAs($this->user);
})->with([
    'empty' => ['', 'Type your GitHub login, octocat, to confirm.'],
    'another login' => ['someone-else', "That doesn't match. Type octocat exactly to confirm."],
    'different case' => ['Octocat', "That doesn't match. Type octocat exactly to confirm."],
]);

it('removes a sign-in method after confirming, while another one remains', function (): void {
    $google = SignInIdentity::factory()->for($this->user)->google('mona@gmail.com')->create();

    Livewire::test('pages::settings.index')
        ->assertSeeHtml('aria-label="Remove Google, mona@gmail.com"')
        ->assertSeeText(['Remove Google?', 'You will no longer sign in to this account with mona@gmail.com.'])
        ->call('removeIdentity', $google->id)
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === 'Removed Google. You can no longer sign in with mona@gmail.com.'
            && $params['dataset']['variant'] === 'success')
        ->assertDontSeeHtml('aria-label="Remove GitHub, @octocat"');

    $this->assertModelMissing($google);
    expect($this->user->signInIdentities()->pluck('login')->all())->toBe(['octocat']);
    $this->assertAuthenticatedAs($this->user);
});

it('removes GitHub for good, so signing in with that GitHub account afterwards creates a new account', function (): void {
    $this->user->update(['github_id' => 583231, 'github_login' => 'octocat']);
    $gitHub = $this->user->signInIdentities()->sole();
    $gitHub->update(['provider_user_id' => '583231']);
    SignInIdentity::factory()->for($this->user)->google('mona@gmail.com')->create();

    Livewire::test('pages::settings.index')
        ->call('removeIdentity', $gitHub->id)
        ->assertHasNoErrors();

    expect($this->user->fresh())
        ->github_id->toBeNull()
        ->github_login->toBeNull();

    $this->post(route('logout'));
    Socialite::fake('github', SocialiteUser::fake(['id' => 583231, 'nickname' => 'octocat', 'name' => 'Mona Lisa Octocat']));

    $this->get(route('auth.github.callback'))->assertRedirect(route('stars.index'));

    $newUser = SignInIdentity::findFor(IdentityProvider::GitHub, '583231')?->user;

    expect($newUser)->toBeInstanceOf(User::class)
        ->and($newUser?->is($this->user))->toBeFalse()
        ->and($this->user->signInIdentities()->pluck('provider')->all())->toBe([IdentityProvider::Google]);
});

it('keeps the only sign-in method, so the account always has a way in', function (): void {
    $gitHub = $this->user->signInIdentities()->sole();

    Livewire::test('pages::settings.index')
        ->assertDontSeeHtml('aria-label="Remove GitHub, @octocat"')
        ->call('removeIdentity', $gitHub->id)
        ->assertHasErrors('identity')
        ->assertSeeText("This is the only way you sign in, so it can't be removed. Add another sign-in method first.");

    $this->assertModelExists($gitHub);
});

it('does not remove another user\'s sign-in method', function (): void {
    SignInIdentity::factory()->for($this->user)->google('mona@gmail.com')->create();
    $theirs = SignInIdentity::factory()->for(User::factory()->signsInWithGitHub('hubot'))->google('hubot@gmail.com')->create();

    Livewire::test('pages::settings.index')
        ->call('removeIdentity', $theirs->id)
        ->assertNotFound();

    $this->assertModelExists($theirs);
});
