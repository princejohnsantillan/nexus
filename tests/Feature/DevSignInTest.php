<?php

declare(strict_types=1);

use App\Enums\DevAccount;
use App\Models\SignInIdentity;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

function enableDevSignIn(string $environment = 'local', bool $flag = true): void
{
    app()['env'] = $environment;

    config(['nexus.dev_sign_in' => $flag]);
}

it('seeds Dev User and Second User, each signing in with a GitHub identity no real account can have', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->orderBy('id')->pluck('name')->all())->toBe(['Dev User', 'Second User'])
        ->and(SignInIdentity::query()->orderBy('id')->get(['provider', 'provider_user_id', 'login'])->toArray())->toBe([
            ['provider' => 'github', 'provider_user_id' => '-1', 'login' => 'dev-user'],
            ['provider' => 'github', 'provider_user_id' => '-2', 'login' => 'second-user'],
        ]);
});

it('restores a deleted dev user when seeded again', function (): void {
    $this->seed(DatabaseSeeder::class);
    DevAccount::Dev->user()?->delete();

    $this->seed(DatabaseSeeder::class);

    expect(DevAccount::Dev->user())->name->toBe('Dev User');
});

it('signs in as a seeded user', function (DevAccount $account, string $name): void {
    $this->seed(DatabaseSeeder::class);
    enableDevSignIn();

    $this->get(route('dev.sign-in', $account))->assertRedirect(route('stars.index'));

    $this->assertAuthenticatedAs(User::query()->where('name', $name)->sole());
})->with([
    'Dev User' => [DevAccount::Dev, 'Dev User'],
    'Second User' => [DevAccount::Second, 'Second User'],
]);

it('is not found outside the local environment or with its flag off', function (string $environment, bool $flag): void {
    $this->seed(DatabaseSeeder::class);
    enableDevSignIn($environment, $flag);

    $this->get(route('dev.sign-in', DevAccount::Dev))->assertNotFound();

    $this->assertGuest();
})->with([
    'production with the flag on' => ['production', true],
    'testing with the flag on' => ['testing', true],
    'local with the flag off' => ['local', false],
]);

it('is not found for an account that is not seeded by Nexus', function (): void {
    enableDevSignIn();

    $this->get('/dev/sign-in/admin')->assertNotFound();
});

it('asks for the seeder when the dev users do not exist yet', function (): void {
    enableDevSignIn();

    $this->get(route('dev.sign-in', DevAccount::Second))->assertRedirect(route('auth.sign-in'));

    $this->assertGuest();

    $this->get(route('auth.sign-in'))->assertSeeText('Run php artisan db:seed to create the dev users, then sign in again.');
});

it('offers the dev sign-in on the sign-in page only when it is enabled', function (): void {
    $this->get(route('auth.sign-in'))->assertOk()->assertDontSeeText('Sign in as Dev User');

    enableDevSignIn();

    $this->get(route('auth.sign-in'))
        ->assertOk()
        ->assertSeeText(['Dev sign-in', 'Sign in as Dev User', 'Sign in as Second User'])
        ->assertSee(route('dev.sign-in', DevAccount::Dev))
        ->assertSee(route('dev.sign-in', DevAccount::Second));
});
