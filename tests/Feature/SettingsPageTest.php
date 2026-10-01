<?php

declare(strict_types=1);

use App\Encryption\SecretCipher;
use App\Models\DataKey;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create([
        'name' => 'Mona Lisa Octocat',
        'github_login' => 'octocat',
        'email' => 'octocat@github.com',
        'avatar_url' => 'https://avatars.githubusercontent.com/u/583231?v=4',
    ]);

    $this->actingAs($this->user);
});

it('shows the GitHub profile and the appearance switch', function (): void {
    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSee('<title>Settings · Nexus</title>', escape: false)
        ->assertSee('https://avatars.githubusercontent.com/u/583231?v=4')
        ->assertSeeTextInOrder(['Name', 'Mona Lisa Octocat', 'GitHub login', 'octocat', 'Email', 'octocat@github.com'])
        ->assertSeeTextInOrder(['Appearance', 'Light', 'Dark', 'System']);
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
    $otherUser = User::factory()->create();

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
