<?php

declare(strict_types=1);

namespace App\Stars;

use App\Models\Connection;
use App\Models\Star;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps each Star's lists in the cache: the rows StarToolset and
 * StarPrompts make its tools and prompts from, and the instructions
 * StarInstructions writes, so clients' requests don't read and work them
 * out again every time.
 *
 * Every Star has a version in the cache, and its lists are cached under
 * it. Anything that changes what a list is made from gives the Star a new
 * version with forget(), so the next request works its lists out afresh;
 * copies under an old version are never read again and expire. That is:
 *
 * - the Star changing, such as its name, description or new-tool policy (Star::booted());
 * - one of its Connections changing in anything but its credentials (its name, "use
 *   for" note, account, server or catalog), or being deleted (Connection::booted());
 * - its switches, its Connections and a Connection's catalog, which are written with
 *   queries Eloquent's events don't see (SwitchStarTools, SwitchStarPrompts,
 *   UpdateStarConnections and RefreshCatalog call forget()).
 *
 * A new version takes effect once the change is committed, so a request
 * reading the database meanwhile can't cache what is about to change under
 * the version that follows it. Versions are random rather than counted, so
 * one that expires or is evicted never comes back to revive the lists
 * cached under it.
 */
final readonly class StarListCache
{
    /**
     * How long a version and the lists cached under it are kept, in
     * seconds. A change takes effect at once however long this is: it
     * clears the copies left under old versions, and bounds how long a
     * change made outside Nexus's code, such as by hand in the database,
     * goes unseen.
     */
    private const int TTL = 3600;

    /**
     * One of the Star's lists as cached under its current version, or else
     * as computed now, which is then cached under that version.
     *
     * @template TList
     *
     * @param  string  $list  Which list, as its cache key names it.
     * @param  Closure(): TList  $compute
     * @param  Closure(mixed): (TList|null)  $restore  The cached copy as the list, or null when there is none or it isn't one.
     * @return TList
     */
    public function remember(Star $star, string $list, Closure $compute, Closure $restore): mixed
    {
        $key = "stars.{$star->id}.lists.{$this->version($star)}.{$list}";
        $cached = $restore(Cache::get($key));

        if ($cached !== null) {
            return $cached;
        }

        $value = $compute();

        Cache::put($key, $value, self::TTL);

        return $value;
    }

    /**
     * Give the Stars new versions, so their lists are worked out afresh:
     * once the transaction in progress is committed, or at once outside
     * one. A change that is rolled back changes nothing.
     */
    public function forget(Star ...$stars): void
    {
        if ($stars === []) {
            return;
        }

        DB::afterCommit(function () use ($stars): void {
            foreach ($stars as $star) {
                Cache::put($this->versionKey($star), Str::random(20), self::TTL);
            }
        });
    }

    /**
     * forget() every Star that includes the Connection.
     */
    public function forgetStarsIncluding(Connection $connection): void
    {
        $this->forget(...$connection->stars()->get(['stars.id'])->all());
    }

    /**
     * The Star's current version, made up now when it has none.
     */
    private function version(Star $star): string
    {
        $key = $this->versionKey($star);
        $version = Cache::get($key);

        if (is_string($version)) {
            return $version;
        }

        $version = Str::random(20);

        if (Cache::add($key, $version, self::TTL)) {
            return $version;
        }

        $current = Cache::get($key);

        return is_string($current) ? $current : $version;
    }

    private function versionKey(Star $star): string
    {
        return "stars.{$star->id}.lists-version";
    }
}
