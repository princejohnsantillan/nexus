<?php

namespace App\Models;

use App\Connectors\Connector;
use App\Connectors\ConnectorCatalog;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Security\SecretCipher;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use SensitiveParameter;

/**
 * A user's signed-in account on one remote MCP server.
 *
 * `settings` holds non-secret configuration (header name, OAuth client id,
 * scope). `secrets` holds everything sensitive, encrypted with the owner's
 * data key; read it with allSecrets() or secret() and write it with
 * putSecrets().
 */
#[Fillable(['connector', 'name', 'description', 'handle', 'url', 'auth_type', 'settings', 'status', 'status_message'])]
#[Hidden(['secrets'])]
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use HasFactory;

    public const HANDLE_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** @var array<string, mixed>|null */
    protected ?array $decryptedSecrets = null;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'auth_type' => ConnectionAuthType::class,
            'status' => ConnectionStatus::class,
            'settings' => 'array',
            'tools_refreshed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ConnectionTool, $this>
     */
    public function tools(): HasMany
    {
        return $this->hasMany(ConnectionTool::class)->orderBy('name');
    }

    /**
     * @return BelongsToMany<Vault, $this>
     */
    public function vaults(): BelongsToMany
    {
        return $this->belongsToMany(Vault::class)->withTimestamps();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function allSecrets(): array
    {
        if ($this->decryptedSecrets !== null) {
            return $this->decryptedSecrets;
        }

        $payload = $this->attributes['secrets'] ?? null;

        return $this->decryptedSecrets = blank($payload)
            ? []
            : app(SecretCipher::class)->decrypt($this->ownerId(), $payload);
    }

    public function secret(string $key, mixed $default = null): mixed
    {
        return $this->allSecrets()[$key] ?? $default;
    }

    /**
     * Merge values into the encrypted secrets. A null value removes the key.
     *
     * @param  array<string, mixed>  $values
     */
    public function putSecrets(#[SensitiveParameter] array $values): static
    {
        $secrets = array_filter(
            [...$this->allSecrets(), ...$values],
            static fn (mixed $value): bool => $value !== null,
        );

        $this->decryptedSecrets = $secrets;
        $this->setAttribute('secrets', $secrets === [] ? null : app(SecretCipher::class)->encrypt($this->ownerId(), $secrets));

        return $this;
    }

    public function forgetDecryptedSecrets(): static
    {
        $this->decryptedSecrets = null;

        return $this;
    }

    /**
     * The catalog connector this connection was created from, if any.
     */
    public function connectorDefinition(): ?Connector
    {
        return ConnectorCatalog::find($this->connector);
    }

    /**
     * Connections to the same host count as the same service, e.g. two Slack
     * workspaces, or two Sentry organisations on mcp.sentry.dev.
     */
    public function serviceKey(): string
    {
        return strtolower((string) parse_url($this->url, PHP_URL_HOST));
    }

    /**
     * How agents see this account: its name, plus who it is signed in as
     * when the server reported that. E.g. "Slack (BetterWorld) · prince@betterworld.org".
     */
    public function accountSummary(): string
    {
        return filled($this->account_identity)
            ? "{$this->name} · {$this->account_identity}"
            : $this->name;
    }

    public function isUsable(): bool
    {
        return $this->status === ConnectionStatus::Active;
    }

    public function markStatus(ConnectionStatus $status, ?string $message = null): static
    {
        $this->forceFill([
            'status' => $status,
            'status_message' => $message === null ? null : mb_substr($message, 0, 1000),
        ])->save();

        return $this;
    }

    public function refresh(): static
    {
        $this->decryptedSecrets = null;

        return parent::refresh();
    }

    protected function ownerId(): int
    {
        return $this->user_id ?? throw new LogicException('Set the connection owner before reading or writing secrets.');
    }
}
