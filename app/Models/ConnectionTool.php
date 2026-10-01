<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ConnectionToolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tool in a Connection's catalog, as its server listed it at the last refresh.
 *
 * `definition` is the tool's JSON exactly as received, objects kept as
 * objects; `definition_hash` is its SHA-256, so a refresh can tell when it
 * changed. The four hints are what the server declared in its annotations,
 * or null when it didn't say.
 *
 * @property int $id
 * @property int $connection_id
 * @property string $name
 * @property string|null $title
 * @property string|null $description
 * @property string $definition
 * @property string $definition_hash
 * @property bool|null $read_only
 * @property bool|null $destructive
 * @property bool|null $idempotent
 * @property bool|null $open_world
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Connection $connection
 *
 * @method static \Database\Factories\ConnectionToolFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereConnectionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereDefinition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereDefinitionHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereDestructive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereIdempotent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereOpenWorld($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereReadOnly($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ConnectionTool whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['name', 'title', 'description', 'definition', 'definition_hash', 'read_only', 'destructive', 'idempotent', 'open_world'])]
class ConnectionTool extends Model
{
    /** @use HasFactory<ConnectionToolFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_only' => 'boolean',
            'destructive' => 'boolean',
            'idempotent' => 'boolean',
            'open_world' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
