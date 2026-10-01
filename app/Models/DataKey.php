<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's own encryption key, stored wrapped by the deployment's master key.
 *
 * Only App\Encryption\DataKeys creates and reads these. Deleting the user
 * cascades to their data key, so the live database can no longer decrypt
 * their secrets. A database backup still holds the wrapped key, so it can be
 * decrypted with the master key until it ages out; shredding backups too
 * needs an external key store (KMS), which comes later.
 *
 * @property int $id
 * @property int $user_id
 * @property string $wrapped_key
 * @property string $wrapper
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataKey newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataKey newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataKey query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataKey whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataKey whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataKey whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataKey whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataKey whereWrappedKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataKey whereWrapper($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'wrapped_key', 'wrapper'])]
#[Hidden(['wrapped_key'])]
class DataKey extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
