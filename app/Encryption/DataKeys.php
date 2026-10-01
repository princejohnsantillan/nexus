<?php

declare(strict_types=1);

namespace App\Encryption;

use App\Concerns\KeepsSecretsInMemory;
use App\Models\DataKey;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * Hands out each user's unwrapped data key, creating it on first use.
 *
 * Scoped to the container's scope: Laravel throws this instance away after
 * every request and every queued job, so unwrapped keys live only in memory,
 * for one request or job, and never reach the cache or the database.
 */
#[Scoped]
final class DataKeys
{
    use KeepsSecretsInMemory;

    /**
     * Unwrapped data keys by user id.
     *
     * @var array<int, string>
     */
    private array $unwrapped = [];

    public function __construct(private readonly KeyWrapper $wrapper) {}

    /**
     * The user's data key, created and stored wrapped if they don't have one yet.
     *
     * @throws DecryptException when the stored key can't be unwrapped
     */
    public function forUser(int $userId): string
    {
        return $this->findForUser($userId) ?? $this->remember($userId, $this->create($userId));
    }

    /**
     * The user's data key, or null when they have none (never had one, or it was deleted with them).
     *
     * @throws DecryptException when the stored key can't be unwrapped
     */
    public function findForUser(int $userId): ?string
    {
        if (array_key_exists($userId, $this->unwrapped)) {
            return $this->unwrapped[$userId];
        }

        $dataKey = DataKey::query()->where('user_id', $userId)->first();

        return $dataKey === null ? null : $this->remember($userId, $dataKey);
    }

    /**
     * Two requests may create a user's first key at once; the unique user_id
     * makes the second one use the first one's key.
     */
    private function create(int $userId): DataKey
    {
        return DataKey::query()->createOrFirst(['user_id' => $userId], [
            'wrapped_key' => $this->wrapper->wrap(Encrypter::generateKey(SecretCipher::CIPHER), $userId),
            'wrapper' => $this->wrapper->name(),
        ]);
    }

    private function remember(int $userId, DataKey $dataKey): string
    {
        if ($dataKey->wrapper !== $this->wrapper->name()) {
            throw new DecryptException("The data key was wrapped by the [{$dataKey->wrapper}] key wrapper, which this deployment doesn't use.");
        }

        return $this->unwrapped[$userId] = $this->wrapper->unwrap($dataKey->wrapped_key, $userId);
    }
}
