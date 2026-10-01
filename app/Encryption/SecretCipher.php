<?php

declare(strict_types=1);

namespace App\Encryption;

use App\Concerns\KeepsSecretsInMemory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Encryption\Encrypter;
use SensitiveParameter;
use Throwable;

/**
 * Encrypts and decrypts a user's secrets (tokens, header values, client
 * secrets) with AES-256-GCM under that user's own data key.
 *
 * Resolve it where you need it rather than holding it in a singleton, so it
 * always uses the current request's or job's data keys.
 */
final readonly class SecretCipher
{
    use KeepsSecretsInMemory;

    public const string CIPHER = 'aes-256-gcm';

    public function __construct(private DataKeys $dataKeys) {}

    /**
     * @param  array<array-key, mixed>  $secrets
     *
     * @throws EncryptException when a value can't be encoded as JSON
     */
    public function encrypt(int $userId, #[SensitiveParameter] array $secrets): string
    {
        return $this->encrypter($this->dataKeys->forUser($userId))
            ->encryptString($this->encode($secrets));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws DecryptException when the ciphertext was not encrypted for this user, was tampered with, or the user's data key is gone
     */
    public function decrypt(int $userId, string $ciphertext): array
    {
        $dataKey = $this->dataKeys->findForUser($userId)
            ?? throw new DecryptException('The user has no data key, so these secrets can no longer be read.');

        $secrets = json_decode($this->encrypter($dataKey)->decryptString($ciphertext), true);

        return is_array($secrets) ? $secrets : throw new DecryptException('The decrypted secrets are not an array.');
    }

    /**
     * Anything thrown inside json_encode(), its own JsonException or an error
     * from a value's jsonSerialize(), carries the native frame with the
     * secrets in its trace, so it is replaced, unchained, by an exception
     * that starts here instead.
     *
     * @param  array<array-key, mixed>  $secrets
     */
    private function encode(#[SensitiveParameter] array $secrets): string
    {
        try {
            return json_encode($secrets, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new EncryptException('The secrets could not be encoded as JSON.');
        }
    }

    private function encrypter(#[SensitiveParameter] string $dataKey): Encrypter
    {
        return new Encrypter($dataKey, self::CIPHER);
    }
}
