<?php

declare(strict_types=1);

namespace App\Casts;

use App\Encryption\Secrets;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Stores a model's secrets as one blob, encrypted with the data key of the
 * user who owns the model (its `user_id`), so callers never touch ciphertext:
 *
 *     $connection->secrets->get('access_token');
 *     $connection->secrets->put(['access_token' => $token, 'refresh_token' => null]);
 *     $connection->save();
 *
 * Hide the column from serialization and never make it fillable.
 *
 * @implements CastsAttributes<Secrets, Secrets>
 */
final class AsEncryptedSecrets implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Secrets
    {
        if ($model->exists && ! $model->wasRecentlyCreated && ! array_key_exists($key, $attributes)) {
            throw new LogicException("Select the [{$key}] column before reading or changing its secrets.");
        }

        if ($value !== null && ! is_string($value)) {
            throw new LogicException("The [{$key}] column must hold ciphertext.");
        }

        return new Secrets($value === '' ? null : $value, $this->ownerId($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value?->ciphertextFor($this->ownerId($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ownerId(array $attributes): ?int
    {
        $ownerId = filter_var($attributes['user_id'] ?? null, FILTER_VALIDATE_INT);

        return $ownerId === false ? null : $ownerId;
    }
}
