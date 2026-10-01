<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StarTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * A bearer token for one Star, named after the client that uses it.
 *
 * A token is `nxs_` and 40 random letters and digits. Only its SHA-256 hash
 * is stored, with its first few characters for the user to recognise it, so
 * the plain token is shown once, when it is created, and never again.
 *
 * @property int $id
 * @property int $star_id
 * @property string $name
 * @property string $token_hash
 * @property string $prefix
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Star $star
 *
 * @method static \Database\Factories\StarTokenFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToken newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToken newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToken query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToken whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToken whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToken whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToken whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToken wherePrefix($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToken whereStarId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToken whereTokenHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToken whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['name'])]
#[Hidden(['token_hash'])]
class StarToken extends Model
{
    /** @use HasFactory<StarTokenFactory> */
    use HasFactory;

    /**
     * What every token starts with, so it is recognisable in a config file or a secret scanner.
     */
    public const string PREFIX = 'nxs_';

    /**
     * How many random letters and digits follow the prefix, about 238 bits.
     */
    public const int RANDOM_LENGTH = 40;

    /**
     * How much of a token is kept to show the user which one it is: the
     * prefix and the first 8 random characters.
     */
    private const int DISPLAYED_LENGTH = 12;

    /**
     * A new plain token. Store only its hash.
     */
    public static function generate(): string
    {
        return self::PREFIX.Str::random(self::RANDOM_LENGTH);
    }

    /**
     * The hash a plain token is stored and looked up by.
     */
    public static function hash(#[SensitiveParameter] string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * The token with this plain text, or null when there is none.
     */
    public static function findByPlainToken(#[SensitiveParameter] string $plainToken): ?self
    {
        if (! str_starts_with($plainToken, self::PREFIX) || strlen($plainToken) !== strlen(self::PREFIX) + self::RANDOM_LENGTH) {
            return null;
        }

        return self::query()->where('token_hash', self::hash($plainToken))->first();
    }

    /**
     * Store this plain token's hash and the part of it shown to the user.
     */
    public function setPlainToken(#[SensitiveParameter] string $plainToken): void
    {
        $this->token_hash = self::hash($plainToken);
        $this->prefix = substr($plainToken, 0, self::DISPLAYED_LENGTH);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Star, $this>
     */
    public function star(): BelongsTo
    {
        return $this->belongsTo(Star::class);
    }

    /**
     * Note that a client just used the token.
     */
    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
