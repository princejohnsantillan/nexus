<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\StarAccessMode;
use Carbon\CarbonImmutable;
use Database\Factories\ActivityEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of one tool call or prompt fetch through a Star: which Star,
 * Connection and name, how it ended, how long it took and how the client
 * authenticated. It is metadata only: never the arguments, the result or
 * any text from the server. It outlives its Star and Connection, whose ids
 * become null when they are deleted. The exposed name is null when the
 * client called without one. Entries older than the retention period
 * (`nexus.activity.retention_days`) are pruned daily.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $star_id
 * @property int|null $connection_id
 * @property ActivityKind $kind
 * @property string|null $exposed_name
 * @property string|null $downstream_name
 * @property ActivityStatus $status
 * @property StarAccessMode $via
 * @property string|null $client_name
 * @property int $duration_ms
 * @property CarbonImmutable $created_at
 * @property-read Connection|null $connection
 * @property-read Star|null $star
 * @property-read User $user
 *
 * @method static \Database\Factories\ActivityEntryFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry whereClientName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry whereConnectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry whereDownstreamName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry whereDurationMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry whereExposedName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry whereStarId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityEntry whereVia($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'star_id', 'connection_id', 'kind', 'exposed_name', 'downstream_name', 'status', 'via', 'client_name', 'duration_ms'])]
final class ActivityEntry extends Model
{
    /** @use HasFactory<ActivityEntryFactory> */
    use HasFactory, MassPrunable;

    /**
     * Entries are written once and never changed.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ActivityKind::class,
            'status' => ActivityStatus::class,
            'via' => StarAccessMode::class,
            'duration_ms' => 'integer',
        ];
    }

    /**
     * How many days entries are kept before the daily prune removes them.
     */
    public static function retentionDays(): int
    {
        return config()->integer('nexus.activity.retention_days');
    }

    /**
     * The entries older than the retention period.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays(self::retentionDays()));
    }

    /**
     * How long the call took, as the log shows it: in milliseconds under
     * ten seconds ("1,204 ms"), then in seconds to a tenth ("55.0 s").
     */
    public function durationForHumans(): string
    {
        if ($this->duration_ms < 10_000) {
            return __(':duration ms', ['duration' => number_format($this->duration_ms)]);
        }

        return __(':duration s', ['duration' => number_format($this->duration_ms / 1000, 1)]);
    }

    /**
     * Whether the call's Star has since been deleted. Every call is
     * recorded with its Star, so a missing one was deleted.
     */
    public function starWasDeleted(): bool
    {
        return $this->star_id === null;
    }

    /**
     * Whether the call's Connection has since been deleted. A call is
     * recorded with a Connection and its downstream name together, or with
     * neither when none of the Star's Connections had the name it called, so
     * a downstream name without a Connection means the Connection was deleted.
     */
    public function connectionWasDeleted(): bool
    {
        return $this->connection_id === null && $this->downstream_name !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Star, $this>
     */
    public function star(): BelongsTo
    {
        return $this->belongsTo(Star::class);
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
