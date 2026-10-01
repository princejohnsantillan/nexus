<?php

namespace App\Mcp\Vaults;

use App\Models\Connection;
use App\Models\Vault;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Writes the MCP server instructions for a vault: what's in it and, when it
 * holds several accounts of one service, how to choose between them.
 *
 * Kept under 2,048 characters, where Claude Code truncates instructions.
 */
class VaultInstructions
{
    public const MAX_LENGTH = 2048;

    public function for(Vault $vault): string
    {
        $connections = $vault->connections()->where('connections.user_id', $vault->user_id)->get();

        foreach ([300, 120, 0] as $useForLimit) {
            $text = $this->compose($vault, $connections, $useForLimit);

            if (mb_strlen($text) <= self::MAX_LENGTH) {
                return $text;
            }
        }

        return Str::limit($text, self::MAX_LENGTH - 1, '…');
    }

    /**
     * @param  Collection<int, Connection>  $connections
     */
    protected function compose(Vault $vault, Collection $connections, int $useForLimit): string
    {
        $sections = [
            "Tools from the \"{$vault->name}\" Nexus vault. Each tool name starts with the handle of the connection it belongs to, e.g. slack__search_messages.",
        ];

        if (filled($vault->description)) {
            $sections[] = (string) $vault->description;
        }

        if ($connections->isNotEmpty()) {
            $sections[] = "Connections:\n".$connections
                ->map(fn (Connection $connection): string => "- {$connection->handle}: {$connection->accountSummary()}"
                    .($useForLimit > 0 && filled($connection->description) ? '. Use for: '.Str::limit($connection->description, $useForLimit) : ''))
                ->implode("\n");
        }

        $shared = $connections
            ->groupBy(fn (Connection $connection): string => $connection->serviceKey())
            ->filter(fn (Collection $accounts): bool => $accounts->count() > 1);

        if ($shared->isNotEmpty()) {
            $groups = $shared->map(fn (Collection $accounts): string => $accounts->pluck('handle')->implode(', '))->implode('; ');

            $sections[] = "Some services have more than one account here ({$groups}). Pick the account from what the user asked for and the \"use for\" notes. "
                .'If it is unclear: for reading or searching, check each matching account and say which account every result came from; '
                .'before sending, creating, changing or deleting anything, ask the user which account to use.';
        }

        return implode("\n\n", $sections);
    }
}
