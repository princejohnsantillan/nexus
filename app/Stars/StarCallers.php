<?php

declare(strict_types=1);

namespace App\Stars;

use App\Enums\McpClient;
use App\Models\ActivityEntry;
use App\Models\Star;
use App\Models\StarOAuthClient;
use App\Models\StarToken;
use Carbon\CarbonImmutable;
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
 * another client, after a watermark taken when listening starts.
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
     * Where the Star stands now, to listen for what comes after: the id of
     * its latest Activity entry (0 when it has none), and when each of its
     * tokens and connected apps was last used, as a Unix time (null when
     * never), by id.
     *
     * @return array{activity: int, tokens: array<int, int|null>, apps: array<int, int|null>}
     */
    public function watermark(Star $star): array
    {
        $latest = $this->activity($star)->max('id');
        $tokens = [];
        $apps = [];

        foreach ($star->tokens()->get(['id', 'last_used_at']) as $token) {
            $tokens[$token->id] = $token->last_used_at?->getTimestamp();
        }

        foreach ($this->connectedApps($star) as $app) {
            $apps[$app['id']] = $app['usedAt']?->getTimestamp();
        }

        return ['activity' => is_numeric($latest) ? (int) $latest : 0, 'tokens' => $tokens, 'apps' => $apps];
    }

    /**
     * When the Star last heard from the client since its watermark, or null
     * when it hasn't: an Activity entry newer than the watermark's, or a
     * token or connected app used since the watermark noted it (any use of
     * one made since). Any of them counts unless its name names another
     * client that can reach the Star.
     *
     * Entries are told apart by id, so a call just after the watermark
     * counts and one just before doesn't, even within one second. Uses are
     * stored to the second, so when the same token or app was used in the
     * second the watermark was taken, another use in that second can't be
     * told from it, and its next use counts instead.
     *
     * @param  array{activity: int, tokens: array<int, int|null>, apps: array<int, int|null>}  $watermark
     */
    public function lastHeardFrom(Star $star, McpClient $client, array $watermark): ?CarbonImmutable
    {
        $others = array_filter(McpClient::for($star->access_mode), fn (McpClient $other): bool => $other !== $client);
        $latest = null;

        foreach ($this->heardSince($star, $watermark) as ['name' => $name, 'at' => $at]) {
            $namesAnother = $name !== null && ! $client->isNamedIn($name)
                && array_any($others, fn (McpClient $other): bool => $other->isNamedIn($name));

            if (! $namesAnother && ($latest === null || $at->greaterThan($latest))) {
                $latest = $at;
            }
        }

        return $latest;
    }

    /**
     * Each name the Star heard from since the watermark, with when it last
     * did: the client names of its newer calls (null for a signed URL), and
     * its tokens and connected apps used since.
     *
     * @param  array{activity: int, tokens: array<int, int|null>, apps: array<int, int|null>}  $watermark
     * @return list<array{name: string|null, at: CarbonImmutable}>
     */
    private function heardSince(Star $star, array $watermark): array
    {
        $heard = $this->activity($star)
            ->where('id', '>', $watermark['activity'])
            ->select('client_name')
            ->selectRaw('max(created_at) as created_at')
            ->groupBy('client_name')
            ->get()
            ->map(fn (ActivityEntry $entry): array => ['name' => $entry->client_name, 'at' => $entry->created_at])
            ->all();

        $used = [
            ...$star->tokens()->whereNotNull('last_used_at')->get()->map(fn (StarToken $token): array => ['name' => $token->name, 'at' => $token->last_used_at, 'before' => $watermark['tokens'][$token->id] ?? null])->all(),
            ...array_map(fn (array $app): array => ['name' => $app['name'], 'at' => $app['usedAt'], 'before' => $watermark['apps'][$app['id']] ?? null], $this->connectedApps($star)),
        ];

        foreach ($used as ['name' => $name, 'at' => $at, 'before' => $before]) {
            if ($at !== null && ($before === null || $at->getTimestamp() > $before)) {
                $heard[] = ['name' => $name, 'at' => $at];
            }
        }

        return array_values($heard);
    }

    /**
     * The Star's connected apps: each one's id, the name it registered
     * with, and when it last reached the Star (null when it hasn't since it
     * was approved).
     *
     * @return list<array{id: int, name: string|null, usedAt: CarbonImmutable|null}>
     */
    private function connectedApps(Star $star): array
    {
        return array_values($star->connectedApps()->with('client')->get()->map(function (StarOAuthClient $app): array {
            $name = $app->client?->getAttribute('name');

            return ['id' => $app->id, 'name' => is_string($name) ? $name : null, 'usedAt' => $app->last_used_at];
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
