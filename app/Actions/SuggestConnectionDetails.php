<?php

declare(strict_types=1);

namespace App\Actions;

use App\Connectors\Connector;
use App\Models\Connection;
use App\Models\User;

class SuggestConnectionDetails
{
    /**
     * The name and handle to suggest for a user's next Connection to a
     * connector: "GitHub" and `github` for the first, then "GitHub 2" and
     * `github-2`, and so on, skipping any name or handle the user already has.
     *
     * @return array{name: string, handle: string}
     */
    public function handle(User $user, Connector $connector): array
    {
        $connections = $user->connections()->get(['name', 'handle']);
        $names = $connections->map(fn (Connection $connection): string => mb_strtolower($connection->name))->flip();
        $handles = $connections->pluck('handle')->flip();

        for ($number = 1; ; $number++) {
            $name = $number === 1 ? $connector->name : "{$connector->name} {$number}";
            $handle = $number === 1 ? $connector->key : "{$connector->key}-{$number}";

            if (! $names->has(mb_strtolower($name)) && ! $handles->has($handle)) {
                return ['name' => $name, 'handle' => $handle];
            }
        }
    }
}
