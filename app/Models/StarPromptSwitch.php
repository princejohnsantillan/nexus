<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StarPromptSwitchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The user's own choice to switch one prompt of a Connection on or off in a
 * Star. It is keyed by the prompt's name, not its catalog row, so it
 * survives catalog refreshes. Prompts without one are on.
 *
 * @property int $id
 * @property int $star_id
 * @property int $connection_id
 * @property string $prompt_name
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Connection $connection
 * @property-read Star $star
 *
 * @method static \Database\Factories\StarPromptSwitchFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarPromptSwitch newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarPromptSwitch newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarPromptSwitch query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarPromptSwitch whereConnectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarPromptSwitch whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarPromptSwitch whereEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarPromptSwitch whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarPromptSwitch wherePromptName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarPromptSwitch whereStarId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StarPromptSwitch whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['connection_id', 'prompt_name', 'enabled'])]
class StarPromptSwitch extends Model
{
    /** @use HasFactory<StarPromptSwitchFactory> */
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
