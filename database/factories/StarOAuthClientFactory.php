<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StarAccessMode;
use App\Models\Star;
use App\Models\StarOAuthClient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Passport\Client;

/**
 * @extends Factory<StarOAuthClient>
 */
class StarOAuthClientFactory extends Factory
{
    /**
     * Define the model's default state: a public client, as MCP clients
     * register, bound to a Star in OAuth mode and not approved yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'star_id' => Star::factory()->withAccessMode(StarAccessMode::OAuth),
            'client_id' => fn (): string => Client::factory()->asPublic()->createOne([
                'name' => 'Claude',
                'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            ])->id,
            'approved_at' => null,
            'last_used_at' => null,
        ];
    }

    /**
     * Indicate that the Star's owner approved the client, making it a connected app.
     */
    public function approved(): static
    {
        return $this->state(fn (): array => ['approved_at' => now()]);
    }
}
