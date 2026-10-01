<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * The RSA key pair Passport signs and checks access tokens with in tests.
 * It is made once per test process, never stored, so no key is committed.
 */
final class PassportKeys
{
    /**
     * @var array{private: string, public: string}|null
     */
    private static ?array $pair = null;

    /**
     * @return array{private: string, public: string}
     */
    public static function pair(): array
    {
        if (self::$pair !== null) {
            return self::$pair;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if ($key === false || $details === false || ! openssl_pkey_export($key, $private) || ! is_string($private)) {
            throw new RuntimeException('Could not make an RSA key pair for Passport.');
        }

        return self::$pair = ['private' => $private, 'public' => $details['key']];
    }
}
