<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StarToolSwitchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The user's own choice to switch one tool of a Connection on or off in a
 * Star. It is keyed by the tool's name, not its catalog row, so it survives
 * catalog refreshes. Tools without one follow the Star's new-tool policy.
 *
 * @property int $id
 * @property int $star_id
 * @property int $connection_id
 * @property string $tool_name
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Connection $connection
 * @property-read Star $star
 *
 * @method static \Database\Factories\StarToolSwitchFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToolSwitch newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToolSwitch newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToolSwitch query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToolSwitch whereConnectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToolSwitch whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToolSwitch whereEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToolSwitch whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToolSwitch whereStarId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToolSwitch whereToolName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarToolSwitch whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['connection_id', 'tool_name', 'enabled'])]
class StarToolSwitch extends Model
{
    /** @use HasFactory<StarToolSwitchFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
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
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
