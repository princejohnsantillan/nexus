<?php

declare(strict_types=1);

use App\Models\DataKey;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\JobCarrying;
use Tests\Fixtures\SecretHolder;

beforeEach(function (): void {
    SecretHolder::createTable();
});

it('stores secrets encrypted and reads them back', function (): void {
    $holder = SecretHolder::query()->create(['user_id' => User::factory()->create()->id]);

    $holder->secrets->put(['access_token' => 'sk-live-123', 'expires_at' => 1_790_000_000]);
    $holder->save();

    expect(DB::table('secret_holders')->value('secrets'))->toBeString()->not->toContain('sk-live-123');

    $reloaded = SecretHolder::query()->sole();
    expect($reloaded->secrets->get('access_token'))->toBe('sk-live-123')
        ->and($reloaded->secrets->all())->toBe(['access_token' => 'sk-live-123', 'expires_at' => 1_790_000_000]);
});

it('merges new secrets in and removes the ones set to null', function (): void {
    $holder = SecretHolder::query()->create(['user_id' => User::factory()->create()->id]);
    $holder->secrets->put(['access_token' => 'old', 'refresh_token' => 'refresh']);
    $holder->save();

    $holder->secrets->put(['access_token' => 'new', 'refresh_token' => null, 'expires_at' => 1_790_000_000]);
    $holder->save();

    expect(SecretHolder::query()->sole()->secrets->all())->toBe(['access_token' => 'new', 'expires_at' => 1_790_000_000]);
});

it('empties the column when every secret is removed', function (): void {
    $holder = SecretHolder::query()->create(['user_id' => User::factory()->create()->id]);
    $holder->secrets->put(['access_token' => 'sk-live-123']);
    $holder->save();

    $holder->secrets->put(['access_token' => null]);
    $holder->save();

    expect(DB::table('secret_holders')->value('secrets'))->toBeNull();
});

it('returns the default for a secret that is not set', function (): void {
    $holder = SecretHolder::query()->create(['user_id' => User::factory()->create()->id]);

    expect($holder->secrets->get('access_token', 'none'))->toBe('none')
        ->and($holder->secrets->all())->toBe([]);
});

it('does not write back secrets that were only read', function (): void {
    $holder = SecretHolder::query()->create(['user_id' => User::factory()->create()->id]);
    $holder->secrets->put(['access_token' => 'first']);
    $holder->save();
    $reader = SecretHolder::query()->sole();
    $reader->secrets->get('access_token');
    $rotator = SecretHolder::query()->sole();
    $rotator->secrets->put(['access_token' => 'rotated']);
    $rotator->save();

    $reader->touch();

    expect(SecretHolder::query()->sole()->secrets->get('access_token'))->toBe('rotated');
});

it('encrypts a change once, so the saved model is clean', function (): void {
    $holder = SecretHolder::query()->create(['user_id' => User::factory()->create()->id]);

    $holder->secrets->put(['access_token' => 'sk-live-123']);
    $holder->save();

    expect($holder->isDirty())->toBeFalse()
        ->and($holder->getRawOriginal('secrets'))->toBe(DB::table('secret_holders')->value('secrets'));
});

it('re-encrypts the secrets for a new owner', function (): void {
    [$first, $second] = User::factory()->count(2)->create();
    $holder = SecretHolder::query()->create(['user_id' => $first->id]);
    $holder->secrets->put(['access_token' => 'sk-live-123']);
    $holder->save();

    $holder->update(['user_id' => $second->id]);

    expect(SecretHolder::query()->sole()->secrets->get('access_token'))->toBe('sk-live-123');
});

it('makes the secrets unreadable once the owner\'s data key is gone', function (): void {
    $holder = SecretHolder::query()->create(['user_id' => User::factory()->create()->id]);
    $holder->secrets->put(['access_token' => 'sk-live-123']);
    $holder->save();
    DataKey::query()->delete();
    app()->forgetScopedInstances();

    SecretHolder::query()->sole()->secrets->get('access_token');
})->throws(DecryptException::class);

it('refuses to read secrets when the column was not selected', function (): void {
    SecretHolder::query()->create(['user_id' => User::factory()->create()->id]);

    SecretHolder::query()->select(['id', 'user_id'])->sole()->secrets->all();
})->throws(LogicException::class, 'Select the [secrets] column');

it('refuses to save secrets without an owner', function (): void {
    $holder = new SecretHolder;

    $holder->secrets->put(['access_token' => 'sk-live-123']);
    $holder->save();
})->throws(LogicException::class, 'Set the owner (user_id) before saving secrets.');

it('queues a model holding secrets with only their ciphertext', function (): void {
    config(['queue.default' => 'database']);
    $holder = SecretHolder::query()->create(['user_id' => User::factory()->create()->id]);
    $holder->secrets->put(['access_token' => 'sk-live-saved']);
    $holder->save();
    $holder->secrets->put(['refresh_token' => 'sk-live-unsaved']);

    Bus::dispatch(new JobCarrying($holder));

    $payload = DB::table('jobs')->sole()->payload;
    expect($payload)->not->toContain('sk-live-saved')->not->toContain('sk-live-unsaved');

    app()->forgetScopedInstances();
    $queuedHolder = unserialize(json_decode((string) $payload, true)['data']['command'])->cargo;
    expect($queuedHolder->secrets->all())->toBe(['access_token' => 'sk-live-saved', 'refresh_token' => 'sk-live-unsaved']);
});

it('refuses to queue the secrets themselves', function (): void {
    config(['queue.default' => 'database']);
    $holder = SecretHolder::query()->create(['user_id' => User::factory()->create()->id]);
    $holder->secrets->put(['access_token' => 'sk-live-123']);
    $holder->save();

    expect(fn (): mixed => Bus::dispatch(new JobCarrying($holder->secrets)))
        ->toThrow(RuntimeException::class, 'App\Encryption\Secrets holds secrets, so it cannot be serialized.');

    expect(DB::table('jobs')->count())->toBe(0);
});
