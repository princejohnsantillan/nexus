<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsEncryptedSecrets;
use App\Encryption\Secrets;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One of a user's accounts on a remote MCP server, and its catalog of tools.
 *
 * Its handle prefixes its tools' names in Stars, so it never changes once
 * the Connection is created. Credentials live in the encrypted `secrets`
 * column (for a header sign-in, `header_value`); everything else about
 * signing in is in the non-secret `settings` (for a header, `header_name`).
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $connector_key
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property string|null $account_identity
 * @property string $url
 * @property ConnectionAuthType $auth_type
 * @property ConnectionStatus $status
 * @property array<array-key, mixed>|null $settings
 * @property Secrets $secrets
 * @property string|null $last_error
 * @property CarbonImmutable|null $catalog_refreshed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ConnectionTool> $tools
 * @property-read int|null $tools_count
 * @property-read User $user
 *
 * @method static \Database\Factories\ConnectionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereAccountIdentity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereAuthType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereCatalogRefreshedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereConnectorKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereHandle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereLastError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereSecrets($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Connection whereUserId($value)
 *
 * @property-read Collection<int, Star> $stars
 * @property-read int|null $stars_count
 *
 * @mixin \Eloquent
 */
#[Fillable(['name', 'handle', 'description', 'url', 'auth_type', 'settings'])]
#[Hidden(['secrets'])]
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use HasFactory;

    /**
     * Lowercase letters, digits and dashes, starting with a letter.
     */
    public const string HANDLE_PATTERN = '/^[a-z][a-z0-9-]*$/';

    public const int HANDLE_MAX_LENGTH = 24;

    /**
     * The header a header sign-in sends unless the user names another.
     */
    public const string DEFAULT_HEADER_NAME = 'Authorization';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected static function booted(): void
    {
        static::updating(function (Connection $connection): void {
            if ($connection->isDirty('handle')) {
                throw new LogicException('A Connection\'s handle never changes once it is created.');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auth_type' => ConnectionAuthType::class,
            'status' => ConnectionStatus::class,
            'settings' => 'array',
            'secrets' => AsEncryptedSecrets::class,
            'catalog_refreshed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ConnectionTool, $this>
     */
    public function tools(): HasMany
    {
        return $this->hasMany(ConnectionTool::class);
    }

    /**
     * The Stars that include this Connection's tools.
     *
     * @return BelongsToMany<Star, $this>
     */
    public function stars(): BelongsToMany
    {
        return $this->belongsToMany(Star::class);
    }

    /**
     * The header a header sign-in sends.
     */
    public function headerName(): string
    {
        $name = $this->settings['header_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : self::DEFAULT_HEADER_NAME;
    }

    /**
     * The value a header sign-in sends, decrypted.
     */
    public function headerValue(): string
    {
        $value = $this->secrets->get('header_value');

        return is_string($value) ? $value : '';
    }
}
