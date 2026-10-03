<?php

declare(strict_types=1);

use App\Enums\IdentityProvider;
use App\Models\EmailCode;
use App\Models\SignInIdentity;
use App\Models\User;
use App\Notifications\EmailCodeNotification;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\SentEmailCodes;

/**
 * Ask for a sign-in code for the address on the sign-in page.
 */
function askForSignInCode(string $email): void
{
    Livewire::test('pages::auth.sign-in')
        ->set('email', $email)
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertRedirect(route('auth.email-code'));
}

it('offers a sign-in code by email under GitHub', function (): void {
    $this->get(route('auth.sign-in'))
        ->assertOk()
        ->assertSeeTextInOrder(['Continue with GitHub', 'or use your email', 'Email', 'Email me a sign-in code', 'We\'ll send a 6-digit code. No password to remember.']);
});

it('emails a 6-digit code that expires in 10 minutes and asks for it', function (): void {
    Notification::fake();
    $this->freezeSecond();

    askForSignInCode(' Ada@Example.com ');

    Notification::assertCount(1);
    $email = SentEmailCodes::all('ada@example.com')[0];
    $mail = SentEmailCodes::mail($email);
    expect($email->code)->toMatch('/^\d{6}$/')
        ->and($mail->subject)->toBe('Your Nexus sign-in code')
        ->and((string) $mail->render())->toContain($email->code, 'Enter this code on the Nexus sign-in page', 'It expires in 10 minutes and works once.')
        ->and(EmailCode::query()->sole()->expires_at->equalTo(now()->addMinutes(10)))->toBeTrue();

    $this->get(route('auth.email-code'))
        ->assertOk()
        ->assertSeeHtml('<title>Check your inbox · Nexus</title>')
        ->assertSeeText(['Check your inbox', 'We sent a 6-digit code to ada@example.com. It expires in 10 minutes.', 'Sign-in code'])
        ->assertSeeText('Paste or type it. You\'re signed in as soon as it\'s complete.')
        ->assertSee('data-flux-otp', escape: false)
        ->assertSee('submit="auto"', escape: false)
        ->assertSeeTextInOrder(['Didn\'t get it? Resend in', '1:00', 'Use another method'])
        ->assertSee('href="'.route('auth.sign-in').'"', escape: false);
});

it('stores only a hash of the code', function (): void {
    Notification::fake();

    askForSignInCode('ada@example.com');

    $code = SentEmailCodes::latest('ada@example.com');
    $stored = EmailCode::query()->sole();

    expect($stored->code_hash)->not->toContain($code)
        ->and(Hash::check($code, $stored->code_hash))->toBeTrue()
        ->and(collect($stored->getAttributes())->filter(fn (mixed $value): bool => is_string($value) && str_contains($value, $code))->keys()->all())->toBe([]);
});

it('answers the same whether or not the address has an account', function (Closure $makeAccounts): void {
    Notification::fake();
    $makeAccounts();
    $users = User::query()->count();

    askForSignInCode('ada@example.com');

    Notification::assertSentOnDemandTimes(EmailCodeNotification::class, 1);
    expect(User::query()->count())->toBe($users);
    $this->get(route('auth.email-code'))->assertOk()->assertSeeText('We sent a 6-digit code to ada@example.com.');
})->with([
    'nobody has it' => [fn (): null => null],
    'someone signs in with it' => [fn (): SignInIdentity => SignInIdentity::factory()->email('ada@example.com')->create()],
    'a GitHub user has it as their email' => [fn (): User => User::factory()->signsInWithGitHub('ada')->create(['email' => 'ada@example.com'])],
]);

it('signs in the user who signs in with the address', function (): void {
    Notification::fake();
    $user = User::factory()->has(SignInIdentity::factory()->email('ada@example.com'), 'signInIdentities')->create();
    User::factory()->has(SignInIdentity::factory()->email('grace@example.com'), 'signInIdentities')->create();

    askForSignInCode('ADA@example.com');

    Livewire::test('pages::auth.email-code')
        ->set('code', SentEmailCodes::latest('ada@example.com'))
        ->call('signIn')
        ->assertHasNoErrors()
        ->assertRedirect(route('stars.index'));

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(2);
});

it('creates an account for an address nobody signs in with, even when another identity or a profile has it', function (): void {
    Notification::fake();
    $gitHubUser = User::factory()->signsInWithGitHub('ada')->create(['email' => 'ada@example.com']);
    $googleUser = User::factory()->has(SignInIdentity::factory()->google('ada@example.com'), 'signInIdentities')->create(['email' => 'ada@example.com']);

    askForSignInCode('ada@example.com');

    Livewire::test('pages::auth.email-code')
        ->set('code', SentEmailCodes::latest('ada@example.com'))
        ->call('signIn')
        ->assertRedirect(route('stars.index'));

    $user = User::query()->whereKeyNot([$gitHubUser->id, $googleUser->id])->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('ada')
        ->and($user->email)->toBe('ada@example.com')
        ->and($user->signInIdentities->map->only('provider', 'provider_user_id', 'login')->all())->toBe([
            ['provider' => IdentityProvider::Email, 'provider_user_id' => 'ada@example.com', 'login' => 'ada@example.com'],
        ])
        ->and($gitHubUser->signInIdentities()->count())->toBe(1)
        ->and($googleUser->signInIdentities()->count())->toBe(1);
});

it('signs in to the account the first sign-in created, the next time', function (): void {
    Notification::fake();
    $this->freezeSecond();

    askForSignInCode('ada@example.com');
    Livewire::test('pages::auth.email-code')->set('code', SentEmailCodes::latest('ada@example.com'))->call('signIn');
    $user = User::query()->sole();
    auth()->logout();

    $this->travel(61)->seconds();
    askForSignInCode('ada@example.com');
    Livewire::test('pages::auth.email-code')->set('code', SentEmailCodes::latest('ada@example.com'))->call('signIn');

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
});

it('returns to the page the guest asked for', function (): void {
    Notification::fake();

    $this->get(route('settings.index'))->assertRedirect(route('auth.sign-in'));
    askForSignInCode('ada@example.com');

    Livewire::test('pages::auth.email-code')
        ->set('code', SentEmailCodes::latest('ada@example.com'))
        ->call('signIn')
        ->assertRedirect(route('settings.index'));
});

it('keeps the digits of a wrong code and says how many tries are left', function (): void {
    Notification::fake();
    askForSignInCode('ada@example.com');
    $wrong = SentEmailCodes::wrong(SentEmailCodes::latest('ada@example.com'));

    Livewire::test('pages::auth.email-code')
        ->set('code', $wrong)
        ->call('signIn')
        ->assertHasErrors('code')
        ->assertSet('code', $wrong)
        ->assertSeeText('That code didn\'t match. Check the latest email, or send a new code. 4 tries left.')
        ->assertSee('data-invalid', escape: false)
        ->assertSeeTextInOrder(['Send a new code in', 'Use another method'])
        ->assertNoRedirect();

    $this->assertGuest();
});

it('stops a code working after 5 wrong tries, until a new one is sent', function (): void {
    Notification::fake();
    $this->freezeSecond();
    askForSignInCode('ada@example.com');
    $code = SentEmailCodes::latest('ada@example.com');
    $page = Livewire::test('pages::auth.email-code');

    foreach (['4 tries left.', '3 tries left.', '2 tries left.', '1 try left.'] as $triesLeft) {
        $page->set('code', SentEmailCodes::wrong($code))->call('signIn')->assertSeeText($triesLeft);
    }

    $page->set('code', SentEmailCodes::wrong($code))->call('signIn')
        ->assertSeeText('That code didn\'t match, and it can\'t be tried again. Send a new code.');
    $page->set('code', $code)->call('signIn')
        ->assertHasErrors('code')
        ->assertSeeText('That code was tried too many times. Send a new code.')
        ->assertNoRedirect();
    $this->assertGuest();

    $this->travel(61)->seconds();
    $page->call('resend');
    $page->set('code', SentEmailCodes::latest('ada@example.com'))->call('signIn')->assertRedirect(route('stars.index'));

    $this->assertAuthenticated();
});

it('refuses a code once it expires', function (): void {
    Notification::fake();
    $this->freezeSecond();
    askForSignInCode('ada@example.com');
    $code = SentEmailCodes::latest('ada@example.com');

    $this->travel(10)->minutes();
    $this->travel(1)->second();

    Livewire::test('pages::auth.email-code')
        ->set('code', $code)
        ->call('signIn')
        ->assertHasErrors('code')
        ->assertSeeText('That code has expired. Send a new code.')
        ->assertNoRedirect();

    $this->assertGuest();
});

it('accepts a code until it expires', function (): void {
    Notification::fake();
    $this->freezeSecond();
    askForSignInCode('ada@example.com');

    $this->travel(10)->minutes();

    Livewire::test('pages::auth.email-code')
        ->set('code', SentEmailCodes::latest('ada@example.com'))
        ->call('signIn')
        ->assertRedirect(route('stars.index'));
});

it('accepts a code only once', function (): void {
    Notification::fake();
    askForSignInCode('ada@example.com');
    $code = SentEmailCodes::latest('ada@example.com');
    $first = Livewire::test('pages::auth.email-code');
    $second = Livewire::test('pages::auth.email-code');

    $first->set('code', $code)->call('signIn')->assertRedirect(route('stars.index'));
    auth()->logout();

    $second->set('code', $code)->call('signIn')
        ->assertHasErrors('code')
        ->assertSeeText('That code can\'t be used any more. Send a new code.')
        ->assertNoRedirect();
    $this->assertGuest();
});

it('resends a new code after the countdown, and only the latest code works', function (): void {
    Notification::fake();
    $this->freezeSecond();
    askForSignInCode('ada@example.com');
    $first = SentEmailCodes::latest('ada@example.com');
    $page = Livewire::test('pages::auth.email-code');

    $this->travel(42)->seconds();
    $page->call('$refresh')->assertSeeTextInOrder(['Resend in', '0:18']);

    $this->travel(18)->seconds();
    $page->call('resend')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === 'We sent a new code to ada@example.com.')
        ->assertSeeTextInOrder(['Resend in', '1:00']);

    $latest = SentEmailCodes::latest('ada@example.com');
    expect(SentEmailCodes::all('ada@example.com'))->toHaveCount(2);

    if ($first !== $latest) {
        $page->set('code', $first)->call('signIn')->assertSeeText('That code didn\'t match.');
    }

    $page->set('code', $latest)->call('signIn')->assertRedirect(route('stars.index'));
});

it('refuses to resend before the countdown ends', function (): void {
    Notification::fake();
    $this->freezeSecond();
    askForSignInCode('ada@example.com');

    $this->travel(30)->seconds();

    Livewire::test('pages::auth.email-code')
        ->call('resend')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['slots']['text'] === 'You can ask for another code in 30 seconds.');

    expect(SentEmailCodes::all('ada@example.com'))->toHaveCount(1);
});

it('makes an address wait a minute between codes', function (): void {
    Notification::fake();
    $this->freezeSecond();
    askForSignInCode('ada@example.com');

    Livewire::test('pages::auth.sign-in')
        ->set('email', 'ada@example.com')
        ->call('sendCode')
        ->assertHasErrors(['email'])
        ->assertSeeText('You can ask for another code in 1 minute.')
        ->assertNoRedirect();

    $this->travel(60)->seconds();
    askForSignInCode('ada@example.com');

    expect(SentEmailCodes::all('ada@example.com'))->toHaveCount(2);
});

it('limits the codes an address gets in an hour', function (): void {
    Notification::fake();
    $this->freezeSecond();
    config(['nexus.limits.email_codes_per_address_per_hour' => 2, 'nexus.limits.email_codes_per_ip_per_hour' => 100]);

    askForSignInCode('ada@example.com');
    $this->travel(2)->minutes();
    askForSignInCode('ada@example.com');
    $this->travel(2)->minutes();

    Livewire::test('pages::auth.sign-in')
        ->set('email', 'ada@example.com')
        ->call('sendCode')
        ->assertHasErrors(['email'])
        ->assertSeeText('You can ask for another code in 56 minutes.');

    askForSignInCode('grace@example.com');

    expect(SentEmailCodes::all('ada@example.com'))->toHaveCount(2)
        ->and(SentEmailCodes::all('grace@example.com'))->toHaveCount(1);
});

it('limits the codes one IP address asks for in an hour', function (): void {
    Notification::fake();
    $this->freezeSecond();
    config(['nexus.limits.email_codes_per_address_per_hour' => 100, 'nexus.limits.email_codes_per_ip_per_hour' => 3]);

    askForSignInCode('ada@example.com');
    askForSignInCode('grace@example.com');
    askForSignInCode('linus@example.com');

    Livewire::test('pages::auth.sign-in')
        ->set('email', 'margaret@example.com')
        ->call('sendCode')
        ->assertHasErrors(['email'])
        ->assertSeeText('You can ask for another code in 1 hour.');

    Notification::assertCount(3);
    expect(SentEmailCodes::all('margaret@example.com'))->toBe([]);
});

it('checks the email address before sending a code', function (string $email, string $message): void {
    Notification::fake();

    Livewire::test('pages::auth.sign-in')
        ->set('email', $email)
        ->call('sendCode')
        ->assertHasErrors(['email'])
        ->assertSeeText($message)
        ->assertNoRedirect();

    Notification::assertNothingSent();
})->with([
    'empty' => ['', 'Enter your email address.'],
    'not an address' => ['ada at example.com', 'Enter a valid email address.'],
]);

it('checks the code is 6 digits before trying it', function (string $code, string $message): void {
    Notification::fake();
    askForSignInCode('ada@example.com');

    Livewire::test('pages::auth.email-code')
        ->set('code', $code)
        ->call('signIn')
        ->assertHasErrors(['code'])
        ->assertSeeText($message);

    expect(EmailCode::query()->sole()->attempts)->toBe(0);
})->with([
    'empty' => ['', 'Enter the 6-digit code from the email.'],
    'too short' => ['123', 'Enter all 6 digits of the code.'],
]);

it('sends a guest who asked for no code back to sign in', function (): void {
    $this->get(route('auth.email-code'))->assertRedirect(route('auth.sign-in'));
});

it('escapes the address on the code page', function (): void {
    Notification::fake();

    askForSignInCode('"<b>ada</b>"@example.com');

    $this->get(route('auth.email-code'))
        ->assertOk()
        ->assertSee('&lt;b&gt;ada&lt;/b&gt;', escape: false)
        ->assertDontSee('<b>ada</b>', escape: false);
});

it('keeps codes out of the application log, even when the log mailer delivers them', function (): void {
    $appLog = storage_path('framework/testing/app-'.Str::random(8).'.log');
    $mailLog = storage_path('framework/testing/mail-'.Str::random(8).'.log');
    config([
        'mail.default' => 'log',
        'logging.default' => 'single',
        'logging.channels.single.path' => $appLog,
        'logging.channels.mail.path' => $mailLog,
    ]);
    $codes = [];
    Event::listen(MessageSending::class, function (MessageSending $event) use (&$codes): void {
        $codes[] = $event->data['code'];
    });
    logger()->info('The application log is written.');

    askForSignInCode('ada@example.com');
    Livewire::test('pages::auth.email-code')->set('code', SentEmailCodes::wrong($codes[0]))->call('signIn');
    Livewire::test('pages::auth.email-code')->set('code', $codes[0])->call('signIn')->assertRedirect(route('stars.index'));

    expect($codes)->toHaveCount(1)
        ->and(file_get_contents($appLog))->toContain('The application log is written.')->not->toContain($codes[0])
        ->and(file_get_contents($mailLog))->toContain($codes[0]);

    unlink($appLog);
    unlink($mailLog);
});

it('hides email sign-in in production until a mailer that delivers is set', function (string $mailer, bool $offered): void {
    app()['env'] = 'production';
    config(['mail.default' => $mailer]);

    $this->get(route('auth.sign-in'))
        ->assertOk()
        ->assertSeeText('Continue with GitHub');

    if ($offered) {
        $this->get(route('auth.sign-in'))->assertSeeText('Email me a sign-in code');
    } else {
        $this->get(route('auth.sign-in'))->assertDontSeeText('Email me a sign-in code');
        Livewire::test('pages::auth.sign-in')->set('email', 'ada@example.com')->call('sendCode')->assertNotFound();
        $this->get(route('auth.email-code'))->assertNotFound();
    }
})->with([
    'the log mailer' => ['log', false],
    'the array mailer' => ['array', false],
    'SMTP' => ['smtp', true],
]);

it('offers email sign-in locally with the log mailer', function (): void {
    app()['env'] = 'local';
    config(['mail.default' => 'log']);

    $this->get(route('auth.sign-in'))->assertOk()->assertSeeText('Email me a sign-in code');
});

it('prunes codes once they expire, once a day', function (): void {
    Notification::fake();
    $this->freezeSecond();
    askForSignInCode('ada@example.com');
    $this->travel(5)->minutes();
    askForSignInCode('grace@example.com');

    $this->travel(5)->minutes();
    $this->travel(1)->second();
    $this->artisan('model:prune', ['--model' => [EmailCode::class]])->assertSuccessful();

    expect(EmailCode::query()->pluck('email')->all())->toBe(['grace@example.com'])
        ->and(collect(resolve(Schedule::class)->events())->sole(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'model:prune'))->command)
        ->toContain(EmailCode::class);
});
