<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SignInIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A user without sign-in identities or the old GitHub columns; give them a
 * GitHub identity with signsInWithGitHub().
 *
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
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'avatar_url' => 'https://avatars.githubusercontent.com/u/'.fake()->randomNumber(8).'?v=4',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user signs in with this GitHub account.
     */
    public function signsInWithGitHub(string $login, ?int $githubId = null): static
    {
        return $this->has(SignInIdentity::factory()->gitHub($login, $githubId), 'signInIdentities');
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
