<?php

namespace App\Security;

use App\Models\DataKey;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Hands out each user's data key, creating it on first use.
 *
 * Unwrapped keys live only in this object's memory for the current request
 * and are never written to the cache or the database.
 */
class DataKeys
{
    /** @var array<int, string> */
    protected array $unwrapped = [];

    /**
     * @param  array<string, KeyWrapper>  $wrappers
     */
    public function __construct(
        protected KeyWrapper $current,
        protected array $wrappers = [],
    ) {
        $this->wrappers[$current->name()] = $current;
    }

    public function forUser(int $userId): string
    {
        return $this->unwrapped[$userId] ??= $this->load($userId) ?? $this->create($userId);
    }

    public function forget(int $userId): void
    {
        unset($this->unwrapped[$userId]);
    }

    protected function load(int $userId): ?string
    {
        $record = DataKey::query()->where('user_id', $userId)->first();

        if ($record === null) {
            return null;
        }

        $wrapper = $this->wrappers[$record->driver]
            ?? throw new RuntimeException("No key wrapper is configured for the [{$record->driver}] driver.");

        return $wrapper->unwrap($record->wrapped_key, $userId);
    }

    protected function create(int $userId): string
    {
        $key = random_bytes(32);

        try {
            DataKey::query()->create([
                'user_id' => $userId,
                'driver' => $this->current->name(),
                'wrapped_key' => $this->current->wrap($key, $userId),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request created the key first; use that one.
            return $this->load($userId) ?? throw new RuntimeException('Unable to load the data key.');
        }

        return $key;
    }
}
