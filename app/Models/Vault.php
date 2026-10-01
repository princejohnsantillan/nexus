<?php

namespace App\Models;

use App\Enums\VaultAuthMode;
use Database\Factories\VaultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

/**
 * What one MCP client gets to see: a set of connections, with per-tool
 * switches, behind its own URL and tokens.
 */
#[Fillable(['name', 'description', 'auth_mode'])]
class Vault extends Model
{
    /** @use HasFactory<VaultFactory> */
    use HasFactory;

    protected $attributes = [
        'auth_mode' => 'token',
        'signed_url_version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'auth_mode' => VaultAuthMode::class,
            'signed_url_version' => 'integer',
        ];
    }

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

    /**
     * The OAuth clients registered for this vault. Their tokens open this
     * vault and nothing else.
     *
     * @return BelongsToMany<Client, $this>
     */
    public function oauthClients(): BelongsToMany
    {
        return $this->belongsToMany(Passport::clientModel(), 'vault_oauth_clients', 'vault_id', 'oauth_client_id')->withTimestamps();
    }

    public function endpointUrl(): string
    {
        return url('/mcp/'.$this->public_id);
    }

    /**
     * The URL clients use: with the credential in it for signed-URL vaults,
     * the plain endpoint otherwise.
     */
    public function clientUrl(): string
    {
        return $this->auth_mode === VaultAuthMode::SignedUrl ? $this->signedUrl() : $this->endpointUrl();
    }

    /**
     * The vault's signed URL. It carries the current version, so rotating
     * the version revokes every URL handed out before.
     */
    public function signedUrl(): string
    {
        return URL::signedRoute('mcp.vault', ['vault' => $this->public_id, 'v' => $this->signed_url_version]);
    }

    public function rotateSignedUrl(): void
    {
        $this->increment('signed_url_version');
    }

    /**
     * The OAuth issuer clients see for this vault. Each vault is its own
     * authorization server to them, so they register a separate client per
     * vault, which is what binds a token to exactly one vault.
     */
    public function oauthIssuer(): string
    {
        return url('/oauth/vaults/'.$this->public_id);
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
