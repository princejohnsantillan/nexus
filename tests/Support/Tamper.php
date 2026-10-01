<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Tampers with ciphertext produced by Laravel's Encrypter.
 */
final class Tamper
{
    /**
     * Flip one bit of the encrypted value, leaving the payload well-formed,
     * so only the authentication tag can catch the change.
     */
    public static function flipBit(string $ciphertext): string
    {
        $payload = json_decode(base64_decode($ciphertext), true, flags: JSON_THROW_ON_ERROR);

        $value = base64_decode($payload['value']);
        $value[0] = $value[0] ^ "\x01";
        $payload['value'] = base64_encode($value);

        return base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
