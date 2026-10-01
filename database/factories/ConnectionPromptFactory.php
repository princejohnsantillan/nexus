<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Connection;
use App\Models\ConnectionPrompt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
        $name = Str::kebab(fake()->unique()->slug(2));
        $description = fake()->sentence();
        $definition = (string) json_encode([
            'name' => $name,
            'description' => $description,
            'arguments' => [['name' => 'topic', 'description' => 'What the prompt is about.', 'required' => true]],
        ]);

        return [
            'connection_id' => Connection::factory(),
            'name' => $name,
            'title' => null,
            'description' => $description,
            'definition' => $definition,
            'definition_hash' => hash('sha256', $definition),
        ];
    }

    /**
     * Indicate that the prompt's definition is this JSON, exactly as a server sent it.
     */
    public function definedAs(string $definition): static
    {
        return $this->state(function (array $attributes) use ($definition): array {
            $decoded = json_decode($definition);
            $name = is_object($decoded) && is_string($decoded->name ?? null) ? $decoded->name : $attributes['name'];

            return [
                'name' => $name,
                'title' => is_object($decoded) && is_string($decoded->title ?? null) ? $decoded->title : null,
                'description' => is_object($decoded) && is_string($decoded->description ?? null) ? $decoded->description : null,
                'definition' => $definition,
                'definition_hash' => hash('sha256', $definition),
            ];
        });
    }
}
