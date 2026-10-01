<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\StarAccessMode;
use Carbon\CarbonImmutable;
use Database\Factories\ActivityEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of one tool call or prompt fetch through a Star: which Star,
 * Connection and name, how it ended, how long it took and how the client
 * authenticated. It is metadata only: never the arguments, the result or
 * any text from the server. It outlives its Star and Connection, whose ids
 * become null when they are deleted.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $star_id
 * @property int|null $connection_id
 * @property ActivityKind $kind
 * @property string $exposed_name
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
class ActivityEntry extends Model
{
    /** @use HasFactory<ActivityEntryFactory> */
    use HasFactory;

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
