<?php

namespace Database\Factories;

use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $handle = Str::lower(fake()->unique()->lexify('svc-????'));

        return [
            'user_id' => User::factory(),
            'name' => Str::headline($handle),
            'handle' => $handle,
            'url' => "https://{$handle}.example.com/mcp",
            'auth_type' => ConnectionAuthType::None,
            'status' => ConnectionStatus::Active,
        ];
    }

    /**
     * Signs in with a static header; the secret is written after creation
     * because it is encrypted with the owner's key.
     */
    public function withHeader(string $value = 'Bearer secret-token', string $name = 'Authorization'): static
    {
        return $this->state(['auth_type' => ConnectionAuthType::Header, 'settings' => ['header_name' => $name]])
            ->afterCreating(fn (Connection $connection) => $connection->putSecrets(['header_value' => $value])->save());
    }

    /**
     * Signed in with OAuth, holding the given tokens.
     */
    public function withOAuthTokens(string $accessToken = 'access-1', ?string $refreshToken = 'refresh-1', ?int $expiresAt = null): static
    {
        return $this->state(['auth_type' => ConnectionAuthType::OAuth])
            ->afterCreating(fn (Connection $connection) => $connection->putSecrets([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresAt,
                'client_id' => 'nexus-client',
            ])->save());
    }
}
