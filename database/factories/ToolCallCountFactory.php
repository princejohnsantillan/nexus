<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ToolCallCount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ToolCallCount>
 */
class ToolCallCountFactory extends Factory
{
    /**
     * Define the model's default state: some calls this week.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'week_starts_on' => ToolCallCount::weekOf(now()),
            'calls' => fake()->numberBetween(1, 2000),
        ];
    }
}
