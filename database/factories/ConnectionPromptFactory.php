<?php

namespace Database\Factories;

use App\Models\Connection;
use App\Models\ConnectionPrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectionPrompt>
 */
class ConnectionPromptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->lexify('prompt-????');
        $definition = json_encode([
            'name' => $name,
            'description' => 'A test prompt.',
            'arguments' => [['name' => 'topic', 'description' => 'What it is about', 'required' => true]],
        ]);

        return [
            'connection_id' => Connection::factory(),
            'name' => $name,
            'description' => 'A test prompt.',
            'definition' => $definition,
            'definition_hash' => hash('sha256', (string) $definition),
        ];
    }
}
