<?php

namespace App\Security;

use Illuminate\Encryption\Encrypter;
use SensitiveParameter;

/**
 * Encrypts a user's secrets (tokens, API keys, client secrets) with that
 * user's own data key.
 */
class SecretCipher
{
    public function __construct(protected DataKeys $keys) {}

    /**
     * @param  array<string, mixed>  $secrets
     */
    public function encrypt(int $userId, #[SensitiveParameter] array $secrets): string
    {
        return $this->encrypter($userId)->encryptString(json_encode($secrets, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function decrypt(int $userId, string $payload): array
    {
        $decoded = json_decode($this->encrypter($userId)->decryptString($payload), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    protected function encrypter(int $userId): Encrypter
    {
        return new Encrypter($this->keys->forUser($userId), 'aes-256-gcm');
    }
}
