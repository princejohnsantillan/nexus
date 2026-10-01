<?php

namespace App\Models;

use Database\Factories\ConnectionToolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use stdClass;

/**
 * A tool the downstream server advertised, cached from its last tools/list.
 */
#[Fillable(['connection_id', 'name', 'title', 'description', 'definition', 'definition_hash', 'read_only', 'destructive'])]
class ConnectionTool extends Model
{
    /** @use HasFactory<ConnectionToolFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'read_only' => 'boolean',
            'destructive' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * The definition decoded with JSON objects kept as objects, so `{}`
     * re-encodes as `{}` and not `[]`.
     */
    public function definitionObject(): stdClass
    {
        $decoded = json_decode($this->definition, false);

        return $decoded instanceof stdClass ? $decoded : new stdClass;
    }

    /**
     * @return array<string, mixed>
     */
    public function definitionArray(): array
    {
        return json_decode($this->definition, true) ?: [];
    }
}
