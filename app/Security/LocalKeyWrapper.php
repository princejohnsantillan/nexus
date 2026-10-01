<?php

namespace App\Security;

use Illuminate\Encryption\Encrypter;
use RuntimeException;
use SensitiveParameter;

/**
 * Wraps data keys with NEXUS_MASTER_KEY. Keep that key out of the database
 * and away from APP_KEY, so a leaked database dump or a leaked APP_KEY alone
 * cannot decrypt credentials.
 */
class LocalKeyWrapper implements KeyWrapper
{
    protected Encrypter $encrypter;

    public function __construct(#[SensitiveParameter] string $masterKey)
    {
        if (str_starts_with($masterKey, 'base64:')) {
            $masterKey = base64_decode(substr($masterKey, 7), true) ?: '';
        }

        if (strlen($masterKey) !== 32) {
            throw new RuntimeException('NEXUS_MASTER_KEY must be a 32-byte key. Generate one with `php artisan nexus:master-key`.');
        }

        $this->encrypter = new Encrypter($masterKey, 'aes-256-gcm');
    }

    public function name(): string
    {
        return 'local';
    }

    public function wrap(#[SensitiveParameter] string $dataKey, int $userId): string
    {
        return $this->encrypter->encryptString($userId.':'.base64_encode($dataKey));
    }

    public function unwrap(string $wrappedKey, int $userId): string
    {
        [$boundUserId, $encodedKey] = explode(':', $this->encrypter->decryptString($wrappedKey), 2) + [null, null];

        if ((string) $userId !== $boundUserId || $encodedKey === null) {
            throw new RuntimeException('The data key does not belong to this user.');
        }

        return base64_decode($encodedKey, true) ?: throw new RuntimeException('The data key is corrupt.');
    }
}
