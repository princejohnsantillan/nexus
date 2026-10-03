<?php

declare(strict_types=1);

use App\Enums\IdentityProvider;
use App\Models\SignInIdentity;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

function deleteGoogleSignInIdentitiesMigration(): Migration
{
    return require database_path('migrations/2026_10_03_153327_delete_google_sign_in_identities.php');
}

/**
 * A Google identity as Google sign-in stored it, before it was removed.
 */
function storeGoogleIdentity(User $user, string $email): void
{
    DB::table('sign_in_identities')->insert([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => fake()->unique()->numerify('1####################'),
        'login' => $email,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('deletes every Google identity, and keeps the GitHub and email identities of the same users', function (): void {
    $gitHubUser = User::factory()->signsInWithGitHub('octocat')->create();
    $emailUser = User::factory()->has(SignInIdentity::factory()->email('ada@example.com'), 'signInIdentities')->create();
    $googleOnlyUser = User::factory()->create();

    storeGoogleIdentity($gitHubUser, 'mona@gmail.com');
    storeGoogleIdentity($emailUser, 'ada@gmail.com');
    storeGoogleIdentity($googleOnlyUser, 'grace@gmail.com');

    deleteGoogleSignInIdentitiesMigration()->up();

    expect(DB::table('sign_in_identities')->where('provider', 'google')->exists())->toBeFalse()
        ->and($gitHubUser->signInIdentities()->get()->map->only('provider', 'login')->all())->toBe([
            ['provider' => IdentityProvider::GitHub, 'login' => 'octocat'],
        ])
        ->and($emailUser->signInIdentities()->get()->map->only('provider', 'login')->all())->toBe([
            ['provider' => IdentityProvider::Email, 'login' => 'ada@example.com'],
        ])
        ->and($googleOnlyUser->signInIdentities()->count())->toBe(0);

    $this->assertModelExists($googleOnlyUser);
});
