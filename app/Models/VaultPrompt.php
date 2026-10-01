<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicit on/off switch for one prompt in one vault.
 */
#[Fillable(['vault_id', 'connection_id', 'prompt_name', 'enabled'])]
class VaultPrompt extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Vault, $this>
     */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }
}
