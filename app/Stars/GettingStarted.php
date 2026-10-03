<?php

declare(strict_types=1);

namespace App\Stars;

use App\Enums\StarAccessMode;
use App\Models\Connection;
use App\Models\Star;
use App\Models\StarToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Passport\Client;

/**
 * The getting-started checklist on the Stars page, which teaches the order
 * of things: connect a server, create a Star, set up a client, receive the
 * first call. Each step is ticked off by the user's own data, never by a
 * click.
 *
 * The checklist closes for good when the user dismisses it, or once every
 * step is done, and that is noted on the user, so from then on the Stars
 * page asks nothing more of the database for it. Until then it takes two
 * queries, however many Stars the user has.
 */
class GettingStarted
{
    /**
     * The user's checklist, or null when it is closed.
     */
    public function checklist(User $user): ?GettingStartedChecklist
    {
        if ($user->getting_started_closed_at !== null) {
            return null;
        }

        $stars = $this->stars($user);
        $star = $stars->first(fn (Star $star): bool => $this->client($star) !== null) ?? $stars->first();

        $checklist = new GettingStartedChecklist(
            connectionNames: $this->connectionNames($user),
            star: $star,
            client: $star instanceof Star ? $this->client($star) : null,
            calledStar: $stars->first(fn (Star $star): bool => $this->wasCalled($star)),
        );

        if ($checklist->isComplete()) {
            $this->close($user);

            return null;
        }

        return $checklist;
    }

    /**
     * Close the user's checklist for good, on every device.
     */
    public function dismiss(User $user): void
    {
        $this->close($user);
    }

    private function close(User $user): void
    {
        $user->forceFill(['getting_started_closed_at' => now()])->save();
    }

    /**
     * The names of the user's Connections, A to Z, each once.
     *
     * @return list<string>
     */
    private function connectionNames(User $user): array
    {
        $names = $user->connections()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['name'])
            ->map(fn (Connection $connection): string => $connection->name);

        return array_values(array_unique($names->all()));
    }

    /**
     * The user's Stars, newest first, each with the newest name of its
     * connected apps and tokens, and whether it has been called: a token or
     * connected app of it was used, or Activity has an entry for it.
     *
     * @return Collection<int, Star>
     */
    private function stars(User $user): Collection
    {
        return $user->stars()
            ->addSelect([
                'newest_app_name' => Client::query()
                    ->select('oauth_clients.name')
                    ->join('star_oauth_clients', 'star_oauth_clients.client_id', '=', 'oauth_clients.id')
                    ->whereColumn('star_oauth_clients.star_id', 'stars.id')
                    ->whereNotNull('star_oauth_clients.approved_at')
                    ->where('oauth_clients.revoked', false)
                    ->orderByDesc('star_oauth_clients.approved_at')
                    ->orderByDesc('star_oauth_clients.id')
                    ->limit(1),
                'newest_token_name' => StarToken::query()
                    ->select('star_tokens.name')
                    ->whereColumn('star_tokens.star_id', 'stars.id')
                    ->orderByDesc('star_tokens.created_at')
                    ->orderByDesc('star_tokens.id')
                    ->limit(1),
            ])
            ->withExists([
                'tokens as has_used_token' => fn (Builder $tokens): Builder => $tokens->whereNotNull('last_used_at'),
                'connectedApps as has_used_app' => fn (Builder $apps): Builder => $apps->whereNotNull('last_used_at'),
                // Entries are indexed by their user, so naming the Star's owner keeps the look to their own activity.
                'activityEntries as has_activity' => fn (Builder $entries): Builder => $entries->whereColumn('activity_entries.user_id', 'stars.user_id'),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * What set up a client for the Star: its newest connected app's or
     * token's name, or its signed URL. Null when it has no client yet.
     */
    private function client(Star $star): ?string
    {
        $app = $star->getAttribute('newest_app_name');
        $token = $star->getAttribute('newest_token_name');

        return match (true) {
            is_string($app) => $app !== '' ? $app : StarAccessMode::OAuth->label(),
            is_string($token) => $token,
            $star->access_mode === StarAccessMode::SignedUrl => StarAccessMode::SignedUrl->label(),
            default => null,
        };
    }

    private function wasCalled(Star $star): bool
    {
        return $star->getAttribute('has_used_token') === true
            || $star->getAttribute('has_used_app') === true
            || $star->getAttribute('has_activity') === true;
    }
}
