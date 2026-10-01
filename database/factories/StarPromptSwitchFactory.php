<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Connection;
use App\Models\Star;
use App\Models\StarPromptSwitch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StarPromptSwitch>
 */
class StarPromptSwitchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'star_id' => Star::factory(),
            'connection_id' => Connection::factory(),
            'prompt_name' => Str::kebab(fake()->unique()->slug(2)),
            'enabled' => false,
        ];
    }
}
