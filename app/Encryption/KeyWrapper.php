<?php

declare(strict_types=1);

namespace App\Encryption;

use Illuminate\Container\Attributes\Bind;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Wraps and unwraps users' data keys with the deployment's master key.
 *
 * Every wrap is bound to its user, so a wrapped key copied onto another
 * user's row does not unwrap.
 */
#[Bind(LocalKeyWrapper::class)]
interface KeyWrapper
{
    /**
     * The name stored next to each wrapped key, so a key is always unwrapped
     * by the wrapper that wrapped it.
     */
    public function name(): string;

    public function wrap(string $dataKey, int $userId): string;

    /**
     * @throws DecryptException when the key was not wrapped for this user with this master key
     */
    public function unwrap(string $wrappedKey, int $userId): string;
}
