<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $login = fake()->unique()->userName();

        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'github_id' => fake()->unique()->numberBetween(1, 999_999_999),
            'github_login' => Str::slug($login),
            'avatar_url' => 'https://avatars.githubusercontent.com/u/'.fake()->randomNumber(8).'?v=4',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user hides their email address on GitHub.
     */
    public function withHiddenEmail(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email' => null,
        ]);
    }
}
