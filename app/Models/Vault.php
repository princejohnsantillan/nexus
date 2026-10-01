<?php

namespace App\Models;

use Database\Factories\VaultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * What one MCP client gets to see: a set of connections, with per-tool
 * switches, behind its own URL and tokens.
 */
#[Fillable(['name', 'description'])]
class Vault extends Model
{
    /** @use HasFactory<VaultFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Vault $vault): void {
            $vault->public_id ??= Str::lower((string) Str::ulid());
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Connection, $this>
     */
    public function connections(): BelongsToMany
    {
        return $this->belongsToMany(Connection::class)->withTimestamps()->orderBy('name');
    }

    /**
     * @return HasMany<VaultTool, $this>
     */
    public function toolOverrides(): HasMany
    {
        return $this->hasMany(VaultTool::class);
    }

    /**
     * @return HasMany<VaultToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(VaultToken::class);
    }

    public function endpointUrl(): string
    {
        return url('/mcp/'.$this->public_id);
    }

    /**
     * A short name for client config entries, e.g. "nexus-work".
     */
    public function clientKey(): string
    {
        return 'nexus-'.(Str::slug($this->name) ?: $this->public_id);
    }

    /**
     * The environment variable the setup snippets read the token from.
     */
    public function tokenEnvVar(): string
    {
        return 'NEXUS_'.Str::upper(Str::snake(Str::slug($this->name, '_') ?: 'vault')).'_TOKEN';
    }
}
