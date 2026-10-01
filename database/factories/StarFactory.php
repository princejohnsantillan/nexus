<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NewToolPolicy;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Star>
 */
class StarFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2, false);

        return [
            'user_id' => User::factory(),
            'name' => Str::headline($slug),
            'slug' => $slug,
            'description' => null,
            'new_tool_policy' => NewToolPolicy::ReadOnly,
        ];
    }

    /**
     * Indicate that the Star includes these Connections, which must belong to its user.
     */
    public function including(Connection ...$connections): static
    {
        return $this->afterCreating(function (Star $star) use ($connections): void {
            $star->connections()->attach(array_map(fn (Connection $connection): int => $connection->id, $connections));
        });
    }

    /**
     * Indicate that tools without the user's own switch follow this policy.
     */
    public function withPolicy(NewToolPolicy $policy): static
    {
        return $this->state(fn (array $attributes): array => [
            'new_tool_policy' => $policy,
        ]);
    }
}
