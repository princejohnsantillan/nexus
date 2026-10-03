<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The migration, run again from before it: down() puts the users table back
 * the way the previous release had it, with the GitHub columns required.
 */
function signInIdentitiesMigration(): Migration
{
    return require database_path('migrations/2026_10_03_062025_create_sign_in_identities_table.php');
}

it('gives every existing user their GitHub identity from the users table', function (): void {
    $migration = signInIdentitiesMigration();
    $migration->down();

    DB::table('users')->insert([
        ['id' => 1, 'name' => 'Mona Lisa Octocat', 'email' => 'octocat@github.com', 'github_id' => 583231, 'github_login' => 'octocat', 'created_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-02-03 04:05:06'],
        ['id' => 2, 'name' => 'Hubot', 'email' => null, 'github_id' => 123_456_789_012, 'github_login' => 'hubot', 'created_at' => '2026-03-04 05:06:07', 'updated_at' => '2026-03-04 05:06:07'],
        ['id' => 3, 'name' => 'Dev User', 'email' => 'dev@example.com', 'github_id' => -1, 'github_login' => 'dev-user', 'created_at' => null, 'updated_at' => null],
    ]);

    $migration->up();

    expect(DB::table('sign_in_identities')->orderBy('user_id')->get(['user_id', 'provider', 'provider_user_id', 'login', 'created_at'])->map(fn (stdClass $row): array => (array) $row)->all())->toBe([
        ['user_id' => 1, 'provider' => 'github', 'provider_user_id' => '583231', 'login' => 'octocat', 'created_at' => '2026-01-02 03:04:05'],
        ['user_id' => 2, 'provider' => 'github', 'provider_user_id' => '123456789012', 'login' => 'hubot', 'created_at' => '2026-03-04 05:06:07'],
        ['user_id' => 3, 'provider' => 'github', 'provider_user_id' => '-1', 'login' => 'dev-user', 'created_at' => null],
    ]);
});

it('leaves the users\' GitHub columns as they were, but no longer required', function (): void {
    $migration = signInIdentitiesMigration();
    $migration->down();

    DB::table('users')->insert(['id' => 1, 'name' => 'Mona Lisa Octocat', 'github_id' => 583231, 'github_login' => 'octocat']);

    $migration->up();

    DB::table('users')->insert(['id' => 2, 'name' => 'Signed up with Google', 'github_id' => null, 'github_login' => null]);

    expect(DB::table('users')->orderBy('id')->get(['github_id', 'github_login'])->map(fn (stdClass $row): array => (array) $row)->all())->toBe([
        ['github_id' => 583231, 'github_login' => 'octocat'],
        ['github_id' => null, 'github_login' => null],
    ]);
});
