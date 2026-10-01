<?php

namespace App\Models;

use App\Enums\ToolCallStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One proxied tool call. Metadata only: arguments and results are never stored.
 */
#[Fillable(['user_id', 'vault_id', 'vault_token_id', 'via', 'connection_id', 'tool_name', 'status', 'duration_ms', 'response_bytes'])]
class ToolCallLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'status' => ToolCallStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Vault, $this>
     */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * @return BelongsTo<VaultToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(VaultToken::class, 'vault_token_id');
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(config('nexus.logs.retention_days')));
    }
}
