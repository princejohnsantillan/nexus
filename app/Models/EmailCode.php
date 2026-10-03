<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailCodePurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time code emailed to an address, for one purpose: signing in with
 * the address, or adding it to the account of the user who asked (user_id).
 *
 * Only its hash is stored. It works once, until it expires, for a limited
 * number of tries; sending a new one replaces it. Expired codes are pruned
 * daily. App\Auth\EmailCodes sends and checks them.
 *
 * @property int $id
 * @property EmailCodePurpose $purpose
 * @property string $email
 * @property int|null $user_id
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailCode newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailCode newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailCode query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailCode whereAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailCode whereCodeHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailCode whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailCode whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailCode whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailCode whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailCode wherePurpose($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailCode whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailCode whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['purpose', 'email', 'user_id', 'code_hash', 'expires_at'])]
#[Hidden(['code_hash'])]
final class EmailCode extends Model
{
    use MassPrunable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => EmailCodePurpose::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The codes sent to an address for a purpose, and for the user who asked
     * when adding it to their account (none when signing in).
     *
     * @return Builder<self>
     */
    public static function sentTo(string $email, EmailCodePurpose $purpose, ?User $user): Builder
    {
        return self::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->where('user_id', $user?->id);
    }

    /**
     * The codes that have expired.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<', now());
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
