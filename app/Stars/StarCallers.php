<?php

declare(strict_types=1);

namespace App\Stars;

use App\Enums\McpClient;
use App\Models\ActivityEntry;
use App\Models\Star;
use App\Models\StarOAuthClient;
use App\Models\StarToken;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which clients have reached a Star, told by the names its credentials
 * carry: the client names in its Activity (a token's name, or an OAuth
 * client's), its tokens' names and its connected apps' names. A name
 * belongs to a client when it names it (`McpClient::isNamedIn()`), so a
 * token named "Cursor" or "cursor-laptop" is Cursor's.
 *
 * A signed URL carries no name, and a token may be named anything, so a
 * call can't always be put down to a client. Listening for a client's
 * first call therefore counts every call except one whose name names
 * another client.
 */
final readonly class StarCallers
{
    /**
     * The clients that can reach the Star in its access mode and have:
     * named in its Activity, by a token of its that has been used, or by a
     * connected app (approving one means it signed in to the Star).
     *
     * @return list<McpClient>
     */
    public function clientsThatCalled(Star $star): array
    {
        $names = [
            ...$this->activity($star)->whereNotNull('client_name')->distinct()->pluck('client_name')->all(),
            ...$star->tokens()->whereNotNull('last_used_at')->pluck('name')->all(),
            ...array_column($this->connectedApps($star), 'name'),
        ];

        return array_values(array_filter(
            McpClient::for($star->access_mode),
            fn (McpClient $client): bool => array_any($names, fn (mixed $name): bool => is_string($name) && $client->isNamedIn($name)),
        ));
    }

    /**
     * When the Star last heard from the client at or after a moment, or
     * null when it hasn't: a call in its Activity, or a request with one of
     * its tokens or from one of its connected apps. Any of them counts
     * unless its name names another client that can reach the Star.
     */
    public function lastHeardFrom(Star $star, McpClient $client, CarbonInterface $since): ?CarbonImmutable
    {
        $others = array_filter(McpClient::for($star->access_mode), fn (McpClient $other): bool => $other !== $client);
        $latest = null;

        foreach ($this->heardSince($star, $since) as ['name' => $name, 'at' => $at]) {
            $namesAnother = $name !== null && ! $client->isNamedIn($name)
                && array_any($others, fn (McpClient $other): bool => $other->isNamedIn($name));

            if (! $namesAnother && ($latest === null || $at->greaterThan($latest))) {
                $latest = $at;
            }
        }

        return $latest;
    }

    /**
     * Each name the Star heard from at or after the moment, with when it
     * last did: the client names of its calls (null for a signed URL), and
     * its tokens and connected apps that were used.
     *
     * @return list<array{name: string|null, at: CarbonImmutable}>
     */
    private function heardSince(Star $star, CarbonInterface $since): array
    {
        $heard = $this->activity($star)
            ->where('created_at', '>=', $since)
            ->select('client_name')
            ->selectRaw('max(created_at) as created_at')
            ->groupBy('client_name')
            ->get()
            ->map(fn (ActivityEntry $entry): array => ['name' => $entry->client_name, 'at' => $entry->created_at])
            ->all();

        $used = [
            ...$star->tokens()->where('last_used_at', '>=', $since)->get()->map(fn (StarToken $token): array => ['name' => $token->name, 'at' => $token->last_used_at])->all(),
            ...array_map(fn (array $app): array => ['name' => $app['name'], 'at' => $app['usedAt']], $this->connectedApps($star)),
        ];

        foreach ($used as ['name' => $name, 'at' => $at]) {
            if ($at?->greaterThanOrEqualTo($since) === true) {
                $heard[] = ['name' => $name, 'at' => $at];
            }
        }

        return array_values($heard);
    }

    /**
     * The Star's connected apps: the name each registered with, and when it
     * last reached the Star (null when it hasn't since it was approved).
     *
     * @return list<array{name: string|null, usedAt: CarbonImmutable|null}>
     */
    private function connectedApps(Star $star): array
    {
        return array_values($star->connectedApps()->with('client')->get()->map(function (StarOAuthClient $app): array {
            $name = $app->client?->getAttribute('name');

            return ['name' => is_string($name) ? $name : null, 'usedAt' => $app->last_used_at];
        })->all());
    }

    /**
     * The Star's activity entries, looked up within its user's, which the
     * activity table indexes by time.
     *
     * @return Builder<ActivityEntry>
     */
    private function activity(Star $star): Builder
    {
        return ActivityEntry::query()->where('user_id', $star->user_id)->where('star_id', $star->id);
    }
}
