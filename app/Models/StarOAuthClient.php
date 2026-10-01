<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StarAccessMode;
use Carbon\CarbonImmutable;
use Database\Factories\StarOAuthClientFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Client;

/**
 * Binds an OAuth client (a Passport client) to the one Star it registered
 * with. An MCP client registers itself with a Star in OAuth mode, so every
 * client belongs to exactly one Star, and its tokens open only that Star:
 * Passport's token endpoint is shared by every Star and can't tell them
 * apart, so this binding is what does.
 *
 * Anyone can register a client, so a client becomes a **connected app** only
 * once the Star's owner approves it (`approved_at`). Revoking it revokes the
 * Passport client and everything issued to it; the binding stays.
 *
 * @property int $id
 * @property int $star_id
 * @property string $client_id
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client|null $client
 * @property-read Star $star
 *
 * @method static \Database\Factories\StarOAuthClientFactory factory($count = null, $state = [])
 * @method static Builder<static>|StarOAuthClient newModelQuery()
 * @method static Builder<static>|StarOAuthClient newQuery()
 * @method static Builder<static>|StarOAuthClient query()
 * @method static Builder<static>|StarOAuthClient whereApprovedAt($value)
 * @method static Builder<static>|StarOAuthClient whereClientId($value)
 * @method static Builder<static>|StarOAuthClient whereCreatedAt($value)
 * @method static Builder<static>|StarOAuthClient whereId($value)
 * @method static Builder<static>|StarOAuthClient whereLastUsedAt($value)
 * @method static Builder<static>|StarOAuthClient whereStarId($value)
 * @method static Builder<static>|StarOAuthClient whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Table('star_oauth_clients')]
class StarOAuthClient extends Model
{
    /** @use HasFactory<StarOAuthClientFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'last_used_at' => 'datetime',
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
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The binding of a client the user may approve: one registered with a
     * Star of theirs that still uses OAuth, and not revoked. Anyone else's
     * Star, or one that switched to another mode, can't be approved for,
     * so null.
     */
    public static function approvableBy(User $user, string $clientId): ?self
    {
        return self::query()
            ->where('client_id', $clientId)
            ->whereHas('star', fn (Builder $stars): Builder => $stars->where('user_id', $user->id)->where('access_mode', StarAccessMode::OAuth))
            ->whereHas('client', fn (Builder $clients): Builder => $clients->where('revoked', false))
            ->with(['star', 'client'])
            ->first();
    }

    /**
     * Note that the Star's owner just approved the client.
     */
    public function markApproved(): void
    {
        $this->forceFill(['approved_at' => now()])->save();
    }

    /**
     * Note that the client just reached the Star.
     */
    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    /**
     * Where the client asked to be sent back after sign-in, one entry per
     * host: the host of an HTTPS or loopback redirect URI, or the scheme
     * and host of a desktop app's own scheme (`cursor://…`).
     *
     * @return list<string>
     */
    public function redirectHosts(): array
    {
        $hosts = [];
        $uris = $this->client?->getAttribute('redirect_uris');

        foreach (is_array($uris) ? $uris : [] as $uri) {
            $scheme = is_string($uri) ? parse_url($uri, PHP_URL_SCHEME) : null;
            $host = is_string($uri) ? parse_url($uri, PHP_URL_HOST) : null;

            if (! is_string($scheme) || ! is_string($host) || $host === '') {
                continue;
            }

            $hosts[] = in_array($scheme, ['http', 'https'], true) ? $host : "{$scheme}://{$host}";
        }

        return array_values(array_unique($hosts));
    }
}
