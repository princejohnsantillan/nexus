<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;
use App\Enums\StarAccessMode;
use App\Models\Star;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateStar
{
    /**
     * How long one creation may hold a user's lock, in seconds; it only
     * counts and inserts, so it needs far less.
     */
    private const int LOCK_SECONDS = 10;

    /**
     * How long another creation for the same user waits for the lock, in seconds.
     */
    private const int WAIT_SECONDS = 5;

    /**
     * The longest slug made from a name, leaving room for a "-2" suffix.
     */
    private const int SLUG_BASE_LENGTH = 40;

    /**
     * Create a Star for the user, including those of the given Connections
     * that are theirs, unless they already have as many Stars as their
     * plan allows (Pro has no limit). Its slug comes from its name,
     * with a number added when another of the user's Stars has it. Creations
     * for one user hold a cache lock while they count and insert, so two at
     * once can't both pass the check.
     *
     * @param  array{name: string, description: string|null}  $attributes
     * @param  list<int>  $connectionIds  The user's Connections to include; anyone else's are ignored.
     * @param  StarAccessMode  $accessMode  How clients will authenticate to it; ChangeStarAccessMode changes it later.
     *
     * @throws ValidationException (under `limit`) when the user is at the limit, or another creation of theirs holds the lock too long
     */
    public function handle(User $user, array $attributes, array $connectionIds = [], StarAccessMode $accessMode = StarAccessMode::Token): Star
    {
        $lock = Cache::lock("users.{$user->id}.new-star", self::LOCK_SECONDS);

        try {
            $lock->block(self::WAIT_SECONDS);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['limit' => __('Another Star is being created in your account. Try again in a moment.')]);
        }

        try {
            if ($user->hasReachedStarLimit()) {
                throw ValidationException::withMessages(['limit' => self::limitMessage()]);
            }

            return DB::transaction(function () use ($user, $attributes, $connectionIds, $accessMode): Star {
                $star = $user->stars()->make($attributes);
                $star->slug = $this->slugFor($user, $attributes['name']);
                $star->access_mode = $accessMode;
                $star->save();

                $star->connections()->attach(
                    $user->connections()->whereKey($connectionIds)->lockForUpdate()->pluck('id')->all(),
                );

                return $star;
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * What a user who has reached the Stars limit is told. Only Free has one.
     */
    public static function limitMessage(): string
    {
        $limit = Plan::Free->starLimit() ?? 0;

        return trans_choice(
            'Free includes :limit Star. Go Pro for more, or delete it if you no longer use it.|Free includes :limit Stars. Go Pro for more, or delete one you no longer use.',
            $limit,
            ['limit' => $limit],
        );
    }

    /**
     * A slug from the name that none of the user's other Stars has: "work",
     * then "work-2", "work-3" and so on.
     */
    private function slugFor(User $user, string $name): string
    {
        $base = trim(Str::limit(Str::slug($name), self::SLUG_BASE_LENGTH, ''), '-');
        $base = $base === '' ? 'star' : $base;

        $taken = $user->stars()
            ->where(fn (Builder $query): Builder => $query->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
            ->pluck('slug')
            ->all();

        $slug = $base;

        for ($number = 2; in_array($slug, $taken, true); $number++) {
            $slug = "{$base}-{$number}";
        }

        return $slug;
    }
}
