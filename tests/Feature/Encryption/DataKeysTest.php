<?php

declare(strict_types=1);

use App\Encryption\DataKeys;
use App\Models\DataKey;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

it('creates a user\'s data key on first use and stores it only wrapped', function (): void {
    $user = User::factory()->create();

    $key = resolve(DataKeys::class)->forUser($user->id);

    $dataKey = DataKey::query()->sole();
    expect(strlen($key))->toBe(32)
        ->and($dataKey->user_id)->toBe($user->id)
        ->and($dataKey->wrapper)->toBe('local')
        ->and($dataKey->wrapped_key)->not->toContain(base64_encode($key));
});

it('keeps using the same data key in later requests', function (): void {
    $user = User::factory()->create();
    $key = resolve(DataKeys::class)->forUser($user->id);
    app()->forgetScopedInstances();

    expect(resolve(DataKeys::class)->forUser($user->id))->toBe($key)
        ->and(DataKey::query()->count())->toBe(1);
});

it('gives each user their own data key', function (): void {
    [$first, $second] = User::factory()->count(2)->create();

    expect(resolve(DataKeys::class)->forUser($first->id))
        ->not->toBe(resolve(DataKeys::class)->forUser($second->id));
});

it('finds no data key for a user who has none, without creating one', function (): void {
    $user = User::factory()->create();

    expect(resolve(DataKeys::class)->findForUser($user->id))->toBeNull()
        ->and(DataKey::query()->count())->toBe(0);
});

it('reads a user\'s data key from the database once per request or job', function (): void {
    $user = User::factory()->create();
    resolve(DataKeys::class)->forUser($user->id);
    app()->forgetScopedInstances();
    DB::enableQueryLog();

    resolve(DataKeys::class)->forUser($user->id);
    resolve(DataKeys::class)->findForUser($user->id);
    $queriesInFirstRequest = count(DB::getQueryLog());
    app()->forgetScopedInstances();
    resolve(DataKeys::class)->findForUser($user->id);

    expect($queriesInFirstRequest)->toBe(1)
        ->and(DB::getQueryLog())->toHaveCount(2);
});

it('deletes the data key with its user', function (): void {
    $user = User::factory()->create();
    resolve(DataKeys::class)->forUser($user->id);

    $user->delete();

    expect(DataKey::query()->count())->toBe(0);
});

it('refuses a wrapped key copied onto another user\'s row', function (): void {
    [$victim, $attacker] = User::factory()->count(2)->create();
    resolve(DataKeys::class)->forUser($victim->id);
    DataKey::query()->create([
        'user_id' => $attacker->id,
        'wrapped_key' => DataKey::query()->where('user_id', $victim->id)->value('wrapped_key'),
        'wrapper' => 'local',
    ]);
    app()->forgetScopedInstances();

    resolve(DataKeys::class)->findForUser($attacker->id);
})->throws(DecryptException::class, 'The data key does not belong to this user.');

it('refuses a data key wrapped by a wrapper this deployment does not use', function (): void {
    $user = User::factory()->create();
    resolve(DataKeys::class)->forUser($user->id);
    DataKey::query()->update(['wrapper' => 'kms']);
    app()->forgetScopedInstances();

    resolve(DataKeys::class)->findForUser($user->id);
})->throws(DecryptException::class, 'The data key was wrapped by the [kms] key wrapper');
