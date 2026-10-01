<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * NEXUS_MASTER_KEY is missing or unusable, so Nexus refuses to encrypt or
 * decrypt anything. The message never contains the configured value.
 */
class MasterKeyException extends RuntimeException
{
    public static function missing(): self
    {
        return new self('NEXUS_MASTER_KEY is not set, so Nexus cannot encrypt or decrypt credentials. Generate one with `php artisan nexus:master-key` and add it to the environment.');
    }

    public static function invalid(): self
    {
        return new self('NEXUS_MASTER_KEY is not a valid master key: it must be 32 random bytes, base64-encoded. Generate one with `php artisan nexus:master-key`.');
    }
}
