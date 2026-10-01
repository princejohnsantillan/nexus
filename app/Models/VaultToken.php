<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A bearer token that opens exactly one vault. Only its SHA-256 hash is
 * stored; the plain token is shown once, when it is issued.
 */
#[Fillable(['name', 'expires_at'])]
#[Hidden(['token_hash'])]
class VaultToken extends Model
{
    public const PREFIX = 'nxs_';

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return array{0: VaultToken, 1: string}
     */
    public static function issue(Vault $vault, string $name, ?Carbon $expiresAt = null): array
    {
        $plain = self::PREFIX.Str::random(48);

        $token = $vault->tokens()->make(['name' => $name, 'expires_at' => $expiresAt]);
        $token->forceFill([
            'token_hash' => self::hash($plain),
            'hint' => substr($plain, 0, 8).'…'.substr($plain, -4),
        ])->save();

        return [$token, $plain];
    }

    public static function findUsable(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        $token = static::query()->where('token_hash', self::hash($plain))->first();

        return $token?->isUsable() ? $token : null;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Record use, writing at most once a minute per token.
     */
    public function markUsed(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lt(now()->subMinute())) {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
