<?php

declare(strict_types=1);

namespace App\Stars;

use App\Actions\UpdateStarConnections;
use App\Enums\ConnectionStatus;
use App\Models\Connection;
use App\Models\Star;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Adding a Connection from a Star's overview, and going back to the Star
 * with it included.
 *
 * "Add a connection" on the overview opens the Connections page's catalog
 * with the Star's public id in `?star=` (addMoreUrl()). The Connections
 * pages carry it while the user picks and connects a server, and a sign-in
 * on the server's own page keeps it in the session with that sign-in
 * (`PendingSignIn::$returnTo`), so it survives the round trip. Once the
 * Connection is made, and signed in if it signs in with OAuth, add() puts
 * it in the Star and the user goes back there. Only the signed-in user's
 * own Star counts: anything else is ignored, as if they came another way.
 */
final readonly class ReturnToStar
{
    /**
     * The query parameter that carries the Star's public id.
     */
    public const string QUERY = 'star';

    public function __construct(private UpdateStarConnections $updateStarConnections) {}

    /**
     * The user's own Star with this public id, or null for anything else.
     */
    public static function find(User $user, mixed $publicId): ?Star
    {
        if (! is_string($publicId) || preg_match('/^[a-z0-9]{'.Star::PUBLIC_ID_LENGTH.'}$/', $publicId) !== 1) {
            return null;
        }

        return $user->stars()->where('public_id', $publicId)->first();
    }

    /**
     * The catalog on the Connections page ("Add more"), adding to the Star
     * when one is given.
     */
    public static function addMoreUrl(?Star $star = null): string
    {
        return route('connections.index', self::query($star)).'#add-more';
    }

    /**
     * The query that carries the Star to another Connections page or the
     * sign-in route, or none without a Star.
     *
     * @return array<string, string>
     */
    public static function query(?Star $star): array
    {
        return $star instanceof Star ? [self::QUERY => $star->public_id] : [];
    }

    /**
     * Add the Connection the user just made to the Star, through
     * UpdateStarConnections, so its tools start from the Star's new-tool
     * policy, and say so in the toast the Star's overview shows when the
     * user is back: "GitHub (Acme) added to Work.", with a warning when its
     * tools didn't load.
     *
     * @return array{variant: string, text: string}
     *
     * @throws ModelNotFoundException<Star> when the Star no longer exists
     */
    public function add(Star $star, Connection $connection): array
    {
        $names = ['connection' => $connection->name, 'star' => $star->name];

        if (! $this->updateStarConnections->add($star, $connection)) {
            return ['variant' => 'success', 'text' => __(':connection is in :star.', $names)];
        }

        return $connection->status === ConnectionStatus::Connected
            ? ['variant' => 'success', 'text' => __(':connection added to :star.', $names)]
            : ['variant' => 'warning', 'text' => __(':connection added to :star, but Nexus couldn\'t load its tools. Its page says why.', $names)];
    }
}
