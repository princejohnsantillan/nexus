<?php

declare(strict_types=1);

use App\Encryption\SecretCipher;
use App\Exceptions\MasterKeyException;
use App\Models\DataKey;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Tests\Support\Tamper;

it('decrypts the secrets it encrypted for a user', function (): void {
    $user = User::factory()->create();
    $secrets = ['header_value' => 'Bearer sk-live-123', 'expires_at' => 1_790_000_000, 'scopes' => ['repo', 'read:org']];

    $ciphertext = resolve(SecretCipher::class)->encrypt($user->id, $secrets);

    expect($ciphertext)->not->toContain('sk-live-123')
        ->and(resolve(SecretCipher::class)->decrypt($user->id, $ciphertext))->toBe($secrets);
});

it('cannot decrypt one user\'s secrets as another user', function (): void {
    [$owner, $other] = User::factory()->count(2)->create();
    resolve(SecretCipher::class)->encrypt($other->id, ['token' => 'theirs']);
    $ciphertext = resolve(SecretCipher::class)->encrypt($owner->id, ['token' => 'mine']);

    resolve(SecretCipher::class)->decrypt($other->id, $ciphertext);
})->throws(DecryptException::class);

it('rejects tampered ciphertext', function (): void {
    $user = User::factory()->create();
    $ciphertext = resolve(SecretCipher::class)->encrypt($user->id, ['token' => 'sk-live-123']);

    resolve(SecretCipher::class)->decrypt($user->id, Tamper::flipBit($ciphertext));
})->throws(DecryptException::class);

it('cannot decrypt anything after the user is deleted and re-created', function (bool $recreatedUserHasEncrypted): void {
    $user = User::factory()->create();
    $ciphertext = resolve(SecretCipher::class)->encrypt($user->id, ['token' => 'sk-live-123']);
    $user->delete();
    app()->forgetScopedInstances();
    $recreated = User::factory()->create(['id' => $user->id]);

    if ($recreatedUserHasEncrypted) {
        resolve(SecretCipher::class)->encrypt($recreated->id, ['token' => 'a new token']);
    }

    expect(fn (): array => resolve(SecretCipher::class)->decrypt($recreated->id, $ciphertext))
        ->toThrow(DecryptException::class);
})->with([
    'before they encrypt anything' => false,
    'after they have a new data key' => true,
]);

it('cannot decrypt with a different master key', function (): void {
    $user = User::factory()->create();
    $ciphertext = resolve(SecretCipher::class)->encrypt($user->id, ['token' => 'sk-live-123']);
    app()->forgetScopedInstances();
    config(['nexus.encryption.master_key' => 'base64:'.base64_encode(random_bytes(32))]);

    resolve(SecretCipher::class)->decrypt($user->id, $ciphertext);
})->throws(DecryptException::class);

it('refuses to encrypt without a master key and stores nothing', function (): void {
    $user = User::factory()->create();
    config(['nexus.encryption.master_key' => null]);

    expect(fn (): string => resolve(SecretCipher::class)->encrypt($user->id, ['token' => 'sk-live-123']))
        ->toThrow(MasterKeyException::class, 'NEXUS_MASTER_KEY is not set');

    expect(DataKey::query()->count())->toBe(0);
});

it('refuses to decrypt without a master key', function (): void {
    $user = User::factory()->create();
    $ciphertext = resolve(SecretCipher::class)->encrypt($user->id, ['token' => 'sk-live-123']);
    app()->forgetScopedInstances();
    config(['nexus.encryption.master_key' => null]);

    resolve(SecretCipher::class)->decrypt($user->id, $ciphertext);
})->throws(MasterKeyException::class, 'NEXUS_MASTER_KEY is not set');
