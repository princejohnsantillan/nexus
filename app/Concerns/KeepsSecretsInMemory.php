<?php

declare(strict_types=1);

namespace App\Concerns;

use LogicException;

/**
 * For objects that hold plaintext secrets or key material in their
 * properties: they never leave the process, and dumps show no values.
 *
 * Serializing one (into a queue payload, the cache or the session) throws
 * instead of writing its properties out; pass the model that owns the
 * secrets instead, which serializes only ciphertext. var_dump(), print_r()
 * and dump() show every property as "[redacted]".
 */
trait KeepsSecretsInMemory
{
    public function __serialize(): never
    {
        throw new LogicException(static::class.' holds secrets, so it cannot be serialized.');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __unserialize(array $data): never
    {
        throw new LogicException(static::class.' holds secrets, so it cannot be unserialized.');
    }

    /**
     * Keyed by the same mangled property names as (array) $this, so Symfony's
     * VarDumper replaces each property's value instead of listing it.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return array_map(fn (): string => '[redacted]', (array) $this);
    }
}
