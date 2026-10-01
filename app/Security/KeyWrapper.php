<?php

namespace App\Security;

/**
 * Wraps and unwraps per-user data keys with a master key.
 *
 * The user id is bound into every wrap, so a wrapped key copied onto another
 * user's row will not unwrap.
 */
interface KeyWrapper
{
    public function name(): string;

    public function wrap(string $dataKey, int $userId): string;

    public function unwrap(string $wrappedKey, int $userId): string;
}
