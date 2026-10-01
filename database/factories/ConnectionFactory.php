<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Connectors\ConnectorCatalog;
use App\Enums\ConnectionAuthType;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

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
        return [
            'user_id' => User::factory(),
            'name' => fake()->company(),
            'handle' => fake()->unique()->regexify('[a-z][a-z0-9]{7}'),
            'description' => null,
            'url' => 'https://mcp.example.com/mcp',
            'auth_type' => ConnectionAuthType::None,
            'status' => ConnectionStatus::Pending,
        ];
    }

    /**
     * Indicate that the Connection signs in with a header, stored encrypted.
     */
    public function withHeader(string $value = 'Bearer sk-test-123', string $name = Connection::DEFAULT_HEADER_NAME): static
    {
        return $this->state(fn (array $attributes): array => [
            'auth_type' => ConnectionAuthType::Header,
            'settings' => ['header_name' => $name],
        ])->afterMaking(function (Connection $connection) use ($value): void {
            $connection->secrets->put(['header_value' => $value]);
        });
    }

    /**
     * Indicate that the Connection was made from a gallery connector, so it
     * signs in to the connector's server.
     */
    public function fromConnector(string $key = 'github'): static
    {
        return $this->state(fn (array $attributes): array => [
            'connector_key' => $key,
            'url' => app(ConnectorCatalog::class)->find($key)->url ?? $attributes['url'],
        ]);
    }

    /**
     * Indicate that the Connection's tools loaded at its last refresh.
     */
    public function connected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ConnectionStatus::Connected,
            'catalog_refreshed_at' => now(),
        ]);
    }

    /**
     * Indicate that the Connection's last refresh failed.
     */
    public function failed(string $error = 'The server answered with HTTP 503.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ConnectionStatus::Error,
            'last_error' => $error,
        ]);
    }
}
