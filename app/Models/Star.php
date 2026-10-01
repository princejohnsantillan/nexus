<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewToolPolicy;
use App\Enums\StarAccessMode;
use Carbon\CarbonImmutable;
use Database\Factories\StarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

/**
 * One MCP server endpoint owned by a user, bundling some of their Connections.
 *
 * It is addressed by its random `public_id`, never its numeric id, so its
 * URL stays the same when it is renamed. Its `slug`, unique among its
 * owner's Stars, names it in client configuration. Each tool of its
 * Connections is on or off by the user's own switch, or else by the Star's
 * new-tool policy; App\Stars\StarToolset works out which.
 *
 * @property int $id
 * @property int $user_id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property StarAccessMode $access_mode
 * @property int $signed_url_version
 * @property NewToolPolicy $new_tool_policy
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Connection> $connections
 * @property-read int|null $connections_count
 * @property-read Collection<int, StarToolSwitch> $toolSwitches
 * @property-read int|null $tool_switches_count
 * @property-read Collection<int, StarToken> $tokens
 * @property-read int|null $tokens_count
 * @property-read User $user
 *
 * @method static \Database\Factories\StarFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star whereAccessMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star whereNewToolPolicy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star whereSignedUrlVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Star whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['name', 'description', 'new_tool_policy'])]
#[RouteKey('public_id')]
class Star extends Model
{
    /** @use HasFactory<StarFactory> */
    use HasFactory;

    /**
     * Lowercase letters and digits, as the route pattern for `{star}` expects.
     */
    private const string PUBLIC_ID_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public const int PUBLIC_ID_LENGTH = 20;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'access_mode' => 'token',
        'signed_url_version' => 1,
        'new_tool_policy' => 'read_only',
    ];

    protected static function booted(): void
    {
        static::creating(function (Star $star): void {
            $star->public_id ??= self::newPublicId();
        });
    }

    /**
     * A random public id: 20 lowercase letters and digits, about 103 bits.
     */
    public static function newPublicId(): string
    {
        $id = '';

        for ($i = 0; $i < self::PUBLIC_ID_LENGTH; $i++) {
            $id .= self::PUBLIC_ID_ALPHABET[random_int(0, strlen(self::PUBLIC_ID_ALPHABET) - 1)];
        }

        return $id;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_mode' => StarAccessMode::class,
            'signed_url_version' => 'integer',
            'new_tool_policy' => NewToolPolicy::class,
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
     * The Connections whose tools the Star includes, all owned by its user.
     *
     * @return BelongsToMany<Connection, $this>
     */
    public function connections(): BelongsToMany
    {
        return $this->belongsToMany(Connection::class);
    }

    /**
     * The user's own on/off choices for tools in this Star.
     *
     * @return HasMany<StarToolSwitch, $this>
     */
    public function toolSwitches(): HasMany
    {
        return $this->hasMany(StarToolSwitch::class);
    }

    /**
     * The bearer tokens clients may use to reach the Star.
     *
     * @return HasMany<StarToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(StarToken::class);
    }

    /**
     * Whether the Star has as many tokens as `nexus.limits.tokens_per_star` allows.
     */
    public function hasReachedTokenLimit(): bool
    {
        return $this->tokens()->count() >= config()->integer('nexus.limits.tokens_per_star');
    }

    /**
     * The URL MCP clients connect to.
     */
    public function endpointUrl(): string
    {
        return route('mcp.star', $this);
    }

    /**
     * The URL a client adds: the signed URL in signed-URL mode, otherwise
     * the endpoint URL.
     */
    public function clientUrl(): string
    {
        return match ($this->access_mode) {
            StarAccessMode::Token => $this->endpointUrl(),
            StarAccessMode::SignedUrl => $this->signedUrl(),
        };
    }

    /**
     * The endpoint URL signed with the Star's signed URL version `v`, which
     * works by itself in signed-URL mode until it is rotated.
     *
     * The signature covers the path and query, not the scheme and host, so
     * the URL stays valid behind a proxy or under another of the app's
     * domains. It is made with the app key, so changing APP_KEY without
     * keeping the old one in APP_PREVIOUS_KEYS invalidates every signed URL.
     */
    public function signedUrl(): string
    {
        return url(URL::signedRoute('mcp.star', ['star' => $this, 'v' => $this->signed_url_version], absolute: false));
    }

    /**
     * Give the Star a new signed URL, which stops the old one working at
     * once. The version is incremented in the database, so two rotations
     * at once both count.
     */
    public function rotateSignedUrl(): void
    {
        $this->increment('signed_url_version');
    }
}
