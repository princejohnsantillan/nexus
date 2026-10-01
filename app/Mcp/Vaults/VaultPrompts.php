<?php

namespace App\Mcp\Vaults;

use App\Models\Connection;
use App\Models\ConnectionPrompt;
use App\Models\Vault;
use App\Models\VaultPrompt;
use Illuminate\Support\Collection;

/**
 * Works out which prompts a vault exposes.
 *
 * Unlike tools, prompts are on unless switched off: a prompt only returns
 * instructions for the agent, and any tool those instructions lead to is
 * still governed by its own switch.
 */
class VaultPrompts
{
    /**
     * @return Collection<int, ExposedPrompt>
     */
    public function all(Vault $vault): Collection
    {
        $connections = $vault->connections()
            ->where('connections.user_id', $vault->user_id)
            ->with('prompts')
            ->get();

        $switches = $vault->promptOverrides()->get()
            ->keyBy(fn (VaultPrompt $switch): string => $switch->connection_id.'|'.$switch->prompt_name);

        $accountsPerService = $connections->countBy(fn (Connection $connection): string => $connection->serviceKey());

        return $connections
            ->flatMap(fn (Connection $connection): Collection => $connection->prompts->map(
                fn (ConnectionPrompt $prompt): ExposedPrompt => new ExposedPrompt(
                    $connection,
                    $prompt,
                    $switches->get($connection->id.'|'.$prompt->name)?->enabled ?? true,
                    hasSiblings: $accountsPerService[$connection->serviceKey()] > 1,
                ),
            ))
            ->values();
    }

    /**
     * @return Collection<int, ExposedPrompt>
     */
    public function enabled(Vault $vault): Collection
    {
        return $this->all($vault)->filter(fn (ExposedPrompt $prompt): bool => $prompt->enabled)->values();
    }

    public function find(Vault $vault, string $name): ?ExposedPrompt
    {
        return $this->enabled($vault)->first(fn (ExposedPrompt $prompt): bool => $prompt->name() === $name);
    }

    public function setEnabled(Vault $vault, ConnectionPrompt $prompt, bool $enabled): void
    {
        $vault->promptOverrides()->updateOrCreate(
            ['connection_id' => $prompt->connection_id, 'prompt_name' => $prompt->name],
            ['enabled' => $enabled],
        );
    }
}
