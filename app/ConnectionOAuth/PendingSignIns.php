<?php

declare(strict_types=1);

namespace App\ConnectionOAuth;

/**
 * The user's sign-ins in progress, kept in their session by the random
 * `state` each one sent to the server's sign-in page. Several can be in
 * progress at once, even to the same server; past the limit, the oldest
 * are forgotten, so abandoned ones don't pile up.
 */
final readonly class PendingSignIns
{
    /**
     * How many sign-ins the session keeps.
     */
    public const int LIMIT = 5;

    private const string SESSION_KEY = 'connection_oauth.pending';

    public function remember(string $state, PendingSignIn $signIn): void
    {
        $pending = $this->all();

        unset($pending[$state]);
        $pending[$state] = $signIn->toArray();

        session()->put(self::SESSION_KEY, array_slice($pending, -self::LIMIT, preserve_keys: true));
    }

    /**
     * Take the sign-in the state belongs to, so it can only be finished once.
     */
    public function pull(string $state): ?PendingSignIn
    {
        $pending = $this->all();
        $signIn = $pending[$state] ?? null;

        unset($pending[$state]);
        session()->put(self::SESSION_KEY, $pending);

        return PendingSignIn::fromArray($signIn);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function all(): array
    {
        $pending = session()->get(self::SESSION_KEY);

        return is_array($pending) ? $pending : [];
    }
}
