<?php

namespace Database\Factories;

use App\Models\Connection;
use App\Models\ConnectionTool;
use Illuminate\Database\Eloquent\Factories\Factory;

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
        $name = fake()->unique()->lexify('tool_????');
        $definition = json_encode([
            'name' => $name,
            'description' => 'A test tool.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) []],
        ]);

        return [
            'connection_id' => Connection::factory(),
            'name' => $name,
            'description' => 'A test tool.',
            'definition' => $definition,
            'definition_hash' => hash('sha256', (string) $definition),
            'read_only' => false,
            'destructive' => true,
        ];
    }

    public function readOnly(): static
    {
        return $this->state(['read_only' => true, 'destructive' => false]);
    }
}
