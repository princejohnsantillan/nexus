<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Star;
use App\Models\StarToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StarToken>
 */
class StarTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plainToken = StarToken::generate();

        return [
            'star_id' => Star::factory(),
            'name' => Str::headline(fake()->unique()->slug(2, false)),
            'token_hash' => StarToken::hash($plainToken),
            'prefix' => substr($plainToken, 0, 12),
            'last_used_at' => null,
        ];
    }

    /**
     * Indicate that the token is this plain token, for a test to send.
     */
    public function plain(string $plainToken): static
    {
        return $this->afterMaking(function (StarToken $token) use ($plainToken): void {
            $token->setPlainToken($plainToken);
        });
    }
}
