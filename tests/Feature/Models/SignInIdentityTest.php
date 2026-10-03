<?php

declare(strict_types=1);

use App\Models\SignInIdentity;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('gives each provider\'s account to one user only', function (): void {
    SignInIdentity::factory()->gitHub('octocat', 583231)->create();
    $otherUser = User::factory()->create();

    expect(fn (): SignInIdentity => DB::transaction(fn (): SignInIdentity => SignInIdentity::factory()->for($otherUser)->gitHub('octocat', 583231)->create()))
        ->toThrow(UniqueConstraintViolationException::class);

    expect($otherUser->signInIdentities()->count())->toBe(0);
});

it('lets the same email address sign in with Google and with a code as different identities', function (): void {
    $user = User::factory()->create();

    SignInIdentity::factory()->for($user)->google('ada@example.com')->create();
    SignInIdentity::factory()->for($user)->email('ada@example.com')->create();

    expect($user->signInIdentities()->count())->toBe(2);
});
