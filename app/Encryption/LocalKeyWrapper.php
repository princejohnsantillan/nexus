<?php

declare(strict_types=1);

namespace App\Encryption;

use App\Concerns\KeepsSecretsInMemory;
use App\Exceptions\MasterKeyException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Wraps data keys with the master key from NEXUS_MASTER_KEY, using AES-256-GCM.
 *
 * Keep the master key apart from the database and from APP_KEY, so that a
 * leaked database dump, or a leaked APP_KEY, decrypts nothing.
 */
final readonly class LocalKeyWrapper implements KeyWrapper
{
    use KeepsSecretsInMemory;

    private const string CIPHER = 'aes-256-gcm';

    private Encrypter $encrypter;

    /**
     * @throws MasterKeyException when the master key is missing or is not 32 base64-encoded bytes
     */
    public function __construct(#[Config('nexus.encryption.master_key'), SensitiveParameter] mixed $masterKey)
    {
        $this->encrypter = new Encrypter($this->decode($masterKey), self::CIPHER);
    }

    public function name(): string
    {
        return 'local';
    }

    /**
     * The wrapped plaintext is "{user id}:{base64 data key}", built without
     * any call that could throw while holding the key.
     */
    public function wrap(#[SensitiveParameter] string $dataKey, int $userId): string
    {
        return $this->encrypter->encryptString($userId.':'.base64_encode($dataKey));
    }

    public function unwrap(string $wrappedKey, int $userId): string
    {
        $plaintext = $this->encrypter->decryptString($wrappedKey);
        $owner = $userId.':';

        if (! str_starts_with($plaintext, $owner)) {
            throw new DecryptException('The data key does not belong to this user.');
        }

        $dataKey = base64_decode(substr($plaintext, strlen($owner)), true);

        if ($dataKey === false || strlen($dataKey) !== 32) {
            throw new DecryptException('The data key is corrupt.');
        }

        return $dataKey;
    }

    /**
     * Accept the key with or without the "base64:" prefix that nexus:master-key prints.
     */
    private function decode(#[SensitiveParameter] mixed $masterKey): string
    {
        if ($masterKey === null || $masterKey === '') {
            throw MasterKeyException::missing();
        }

        $key = is_string($masterKey) ? base64_decode(Str::chopStart($masterKey, 'base64:'), true) : false;

        if ($key === false || strlen($key) !== 32) {
            throw MasterKeyException::invalid();
        }

        return $key;
    }
}
