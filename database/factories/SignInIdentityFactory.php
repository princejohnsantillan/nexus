<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IdentityProvider;
use App\Models\SignInIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SignInIdentity>
 */
class SignInIdentityFactory extends Factory
{
    /**
     * Define the model's default state: a GitHub identity.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => IdentityProvider::GitHub,
            'provider_user_id' => (string) fake()->unique()->numberBetween(1, 999_999_999),
            'login' => Str::slug(fake()->unique()->userName()),
        ];
    }

    /**
     * Indicate that the identity is this GitHub account.
     */
    public function gitHub(string $login, ?int $githubId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => IdentityProvider::GitHub,
            'provider_user_id' => (string) ($githubId ?? fake()->unique()->numberBetween(1, 999_999_999)),
            'login' => $login,
        ]);
    }

    /**
     * Indicate that the identity is a Google account with this email address.
     */
    public function google(string $email): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => IdentityProvider::Google,
            'provider_user_id' => (string) fake()->unique()->numerify('1####################'),
            'login' => $email,
        ]);
    }

    /**
     * Indicate that the identity is this email address, signed in with a code.
     */
    public function email(string $email): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => IdentityProvider::Email,
            'provider_user_id' => Str::lower($email),
            'login' => Str::lower($email),
        ]);
    }
}
