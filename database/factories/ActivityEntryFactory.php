<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\StarAccessMode;
use App\Models\ActivityEntry;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityEntry>
 */
class ActivityEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tool = str_replace('-', '_', fake()->unique()->slug(2, false));

        return [
            'user_id' => User::factory(),
            'star_id' => null,
            'connection_id' => null,
            'kind' => ActivityKind::Tool,
            'exposed_name' => 'example__'.$tool,
            'downstream_name' => $tool,
            'status' => ActivityStatus::Ok,
            'via' => StarAccessMode::Token,
            'client_name' => 'Laptop',
            'duration_ms' => fake()->numberBetween(20, 2000),
        ];
    }

    /**
     * Indicate that the entry records a call to this tool of the Connection
     * through the Star, both owned by the entry's user.
     */
    public function through(Star $star, Connection $connection, string $tool = 'search'): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $star->user_id,
            'star_id' => $star->id,
            'connection_id' => $connection->id,
            'exposed_name' => $connection->handle.'__'.$tool,
            'downstream_name' => $tool,
        ]);
    }

    /**
     * Indicate that the call ended this way.
     */
    public function withStatus(ActivityStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }
}
