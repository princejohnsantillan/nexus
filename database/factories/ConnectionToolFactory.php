<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Connection;
use App\Models\ConnectionTool;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConnectionTool>
 */
class ConnectionToolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::snake(fake()->unique()->slug(2));
        $description = fake()->sentence();
        $definition = (string) json_encode([
            'name' => $name,
            'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
        ]);

        return [
            'connection_id' => Connection::factory(),
            'name' => $name,
            'title' => null,
            'description' => $description,
            'definition' => $definition,
            'definition_hash' => hash('sha256', $definition),
            'read_only' => null,
            'destructive' => null,
            'idempotent' => null,
            'open_world' => null,
        ];
    }
}
