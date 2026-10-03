<?php

declare(strict_types=1);

use App\Auth\PendingEmailSignIn;
use App\Enums\EmailCodePurpose;
use App\Enums\IdentityProvider;
use App\Models\SignInIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\SentEmailCodes;

beforeEach(function (): void {
    $this->user = User::factory()->signsInWithGitHub('octocat')->create();

    $this->actingAs($this->user);
});

it('offers to add an email address in the sign-in methods', function (): void {
    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSeeTextInOrder(['Sign-in methods', 'GitHub', '@octocat', 'Add email', 'Appearance']);
});

it('adds an email address once the code sent to it is entered, and it then signs in to this account', function (): void {
    Notification::fake();

    $page = Livewire::test('pages::settings.index')
        ->set('newEmail', ' Mona@Example.com ')
        ->call('sendNewEmailCode')
        ->assertHasNoErrors()
        ->assertSet('newEmailSentTo', 'mona@example.com')
        ->assertSeeText(['Check your inbox', 'We sent a 6-digit code to mona@example.com. It expires in 10 minutes.']);

    $email = SentEmailCodes::all('mona@example.com', EmailCodePurpose::AddToAccount)[0];
    expect(SentEmailCodes::mail($email)->subject)->toBe('Confirm your email address for Nexus')
        ->and((string) SentEmailCodes::mail($email)->render())->toContain($email->code, 'Enter this code in Nexus Settings');

    $page->set('newEmailCode', $email->code)
        ->call('addNewEmail')
        ->assertHasNoErrors()
        ->assertSet('newEmailSentTo', null)
        ->assertDispatched('modal-close', name: 'add-email')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === 'Added. You can now sign in with mona@example.com.')
        ->assertSeeTextInOrder(['Sign-in methods', 'GitHub', '@octocat', 'Email', 'mona@example.com']);

    expect($this->user->signInIdentities()->where('provider', IdentityProvider::Email)->sole()->only('provider_user_id', 'login'))
        ->toBe(['provider_user_id' => 'mona@example.com', 'login' => 'mona@example.com']);

    auth()->logout();
    $this->travel(61)->seconds();
    Livewire::test('pages::auth.sign-in')->set('email', 'mona@example.com')->call('sendCode');
    Livewire::test('pages::auth.email-code')
        ->set('code', SentEmailCodes::latest('mona@example.com'))
        ->call('signIn')
        ->assertRedirect(route('stars.index'));

    $this->assertAuthenticatedAs($this->user);
});

it('refuses an address another user signs in with, once the code proves it', function (): void {
    Notification::fake();
    $other = User::factory()->has(SignInIdentity::factory()->email('mona@example.com'), 'signInIdentities')->create();

    $page = Livewire::test('pages::settings.index')
        ->set('newEmail', 'mona@example.com')
        ->call('sendNewEmailCode')
        ->assertHasNoErrors()
        ->assertSet('newEmailSentTo', 'mona@example.com');

    $page->set('newEmailCode', SentEmailCodes::latest('mona@example.com', EmailCodePurpose::AddToAccount))
        ->call('addNewEmail')
        ->assertHasErrors(['newEmail'])
        ->assertSet('newEmailSentTo', null)
        ->assertSeeText('This address already signs in to another Nexus account, so it can\'t be added to this one.');

    expect(SignInIdentity::query()->where('provider', IdentityProvider::Email)->pluck('user_id')->all())->toBe([$other->id])
        ->and($this->user->signInIdentities()->count())->toBe(1);
});

it('refuses an address the user already signs in with, without sending a code', function (): void {
    Notification::fake();
    SignInIdentity::factory()->for($this->user)->email('mona@example.com')->create();

    Livewire::test('pages::settings.index')
        ->set('newEmail', 'MONA@example.com')
        ->call('sendNewEmailCode')
        ->assertHasErrors(['newEmail'])
        ->assertSeeText('You already sign in with this address.')
        ->assertSet('newEmailSentTo', null);

    Notification::assertNothingSent();
});

it('keeps a wrong code\'s digits and adds nothing', function (): void {
    Notification::fake();

    $page = Livewire::test('pages::settings.index')
        ->set('newEmail', 'mona@example.com')
        ->call('sendNewEmailCode');
    $wrong = SentEmailCodes::wrong(SentEmailCodes::latest('mona@example.com', EmailCodePurpose::AddToAccount));

    $page->set('newEmailCode', $wrong)
        ->call('addNewEmail')
        ->assertHasErrors(['newEmailCode'])
        ->assertSet('newEmailCode', $wrong)
        ->assertSeeText('That code didn\'t match. Check the latest email, or send a new code. 4 tries left.')
        ->assertNotDispatched('modal-close');

    expect($this->user->signInIdentities()->count())->toBe(1);
});

it('keeps codes to its purpose: a code to add an address doesn\'t sign in, and a sign-in code doesn\'t add one', function (): void {
    Notification::fake();
    $this->freezeSecond();

    Livewire::test('pages::settings.index')->set('newEmail', 'mona@example.com')->call('sendNewEmailCode');
    $addCode = SentEmailCodes::latest('mona@example.com', EmailCodePurpose::AddToAccount);

    auth()->logout();
    PendingEmailSignIn::remember('mona@example.com');
    Livewire::test('pages::auth.email-code')
        ->set('code', $addCode)
        ->call('signIn')
        ->assertHasErrors(['code'])
        ->assertSeeText('That code can\'t be used any more. Send a new code.');
    $this->assertGuest();

    $this->travel(61)->seconds();
    Livewire::test('pages::auth.sign-in')->set('email', 'mona@example.com')->call('sendCode');
    $signInCode = SentEmailCodes::latest('mona@example.com');

    $this->actingAs($this->user);
    $this->travel(61)->seconds();
    $page = Livewire::test('pages::settings.index')->set('newEmail', 'mona@example.com')->call('sendNewEmailCode');
    $newAddCode = SentEmailCodes::latest('mona@example.com', EmailCodePurpose::AddToAccount);

    if ($signInCode !== $newAddCode) {
        $page->set('newEmailCode', $signInCode)->call('addNewEmail')->assertHasErrors(['newEmailCode']);
    }

    expect($this->user->signInIdentities()->count())->toBe(1);
});

it('resends the code after the countdown, and goes back to change the address', function (): void {
    Notification::fake();
    $this->freezeSecond();

    $page = Livewire::test('pages::settings.index')
        ->set('newEmail', 'mona@example.com')
        ->call('sendNewEmailCode')
        ->assertSeeTextInOrder(['Didn\'t get it? Resend in', '1:00', 'Use another address']);

    $this->travel(60)->seconds();
    $page->call('resendNewEmailCode')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === 'We sent a new code to mona@example.com.');
    expect(SentEmailCodes::all('mona@example.com', EmailCodePurpose::AddToAccount))->toHaveCount(2);

    $page->call('changeNewEmail')
        ->assertSet('newEmailSentTo', null)
        ->assertSet('newEmail', 'mona@example.com')
        ->assertSeeText('Send code');
});

it('starts over when the modal closes', function (): void {
    Notification::fake();

    Livewire::test('pages::settings.index')
        ->set('newEmail', 'mona@example.com')
        ->call('sendNewEmailCode')
        ->set('newEmailCode', '12')
        ->call('resetNewEmail')
        ->assertSet('newEmail', '')
        ->assertSet('newEmailSentTo', null)
        ->assertSet('newEmailCode', '');
});

it('limits the codes sent to the address', function (): void {
    Notification::fake();
    $this->freezeSecond();

    Livewire::test('pages::settings.index')
        ->set('newEmail', 'mona@example.com')
        ->call('sendNewEmailCode')
        ->call('changeNewEmail')
        ->call('sendNewEmailCode')
        ->assertHasErrors(['newEmail'])
        ->assertSeeText('You can ask for another code in 1 minute.');

    expect(SentEmailCodes::all('mona@example.com', EmailCodePurpose::AddToAccount))->toHaveCount(1);
});

it('checks the address before sending a code', function (string $email, string $message): void {
    Notification::fake();

    Livewire::test('pages::settings.index')
        ->set('newEmail', $email)
        ->call('sendNewEmailCode')
        ->assertHasErrors(['newEmail'])
        ->assertSeeText($message);

    Notification::assertNothingSent();
})->with([
    'empty' => ['', 'Enter the email address to add.'],
    'not an address' => ['mona at example.com', 'Enter a valid email address.'],
]);

it('hides adding an email address in production until a mailer that delivers is set', function (): void {
    app()['env'] = 'production';
    config(['mail.default' => 'log']);

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSeeText('Sign-in methods')
        ->assertDontSeeText('Add email');

    Livewire::test('pages::settings.index')
        ->set('newEmail', 'mona@example.com')
        ->call('sendNewEmailCode')
        ->assertNotFound();
});
