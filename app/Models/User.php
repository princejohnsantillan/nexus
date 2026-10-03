<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\IdentityProvider;
use App\Enums\Plan;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * A person using Nexus. Nexus stores no password: the user signs in through
 * one of their sign-in identities (see SignInIdentity), and the provider
 * handles the password and any second factor.
 *
 * The GitHub columns are left from before identities. GitHub sign-in keeps
 * them up to date, for code that still reads them, but they may be empty:
 * show the user with gitHubLogin() and signInName() instead.
 *
 * MCP clients of the user's Stars in OAuth mode act as the user with the
 * Passport access tokens Nexus issues them (HasApiTokens).
 *
 * `getting_started_closed_at` notes when the getting-started checklist on
 * the Stars page closed for good: the user dismissed it or did every step
 * (App\Stars\GettingStarted).
 *
 * `pro_until` is when the user's prepaid Pro ends: they are on Pro while it
 * is in the future, and on Free otherwise (plan()). Paying moves it forward
 * from nextProStart(); nothing renews it on its own.
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property int|null $github_id
 * @property string|null $github_login
 * @property string|null $avatar_url
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $getting_started_closed_at
 * @property CarbonImmutable|null $pro_until
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereAvatarUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereGettingStartedClosedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereGithubId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereGithubLogin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereProUntil($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 *
 * @property-read Collection<int, Connection> $connections
 * @property-read int|null $connections_count
 * @property-read Collection<int, Star> $stars
 * @property-read int|null $stars_count
 * @property-read Collection<int, ActivityEntry> $activityEntries
 * @property-read int|null $activity_entries_count
 * @property-read Collection<int, SignInIdentity> $signInIdentities
 * @property-read int|null $sign_in_identities_count
 *
 * @mixin \Eloquent
 */
#[Fillable(['name', 'email', 'github_id', 'github_login', 'avatar_url'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * How many days before Pro ends the app starts warning that it will.
     */
    public const int PRO_ENDING_SOON_DAYS = 7;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_id' => 'integer',
            'getting_started_closed_at' => 'datetime',
            'pro_until' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Connection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    /**
     * Whether the user has as many Connections as their plan allows. Pro never does.
     */
    public function hasReachedConnectionLimit(): bool
    {
        $limit = $this->plan()->connectionLimit();

        return $limit !== null && $this->connections()->count() >= $limit;
    }

    /**
     * @return HasMany<Star, $this>
     */
    public function stars(): HasMany
    {
        return $this->hasMany(Star::class);
    }

    /**
     * Whether the user has as many Stars as their plan allows. Pro never does.
     */
    public function hasReachedStarLimit(): bool
    {
        $limit = $this->plan()->starLimit();

        return $limit !== null && $this->stars()->count() >= $limit;
    }

    /**
     * Pro while `pro_until` is in the future, Free otherwise.
     */
    public function plan(): Plan
    {
        return $this->pro_until?->isFuture() === true ? Plan::Pro : Plan::Free;
    }

    /**
     * How many days of Pro the user has left, counting a part of a day as a
     * whole one, or null when they are on Free.
     */
    public function proDaysLeft(): ?int
    {
        if ($this->pro_until?->isFuture() !== true) {
            return null;
        }

        return (int) ceil(CarbonImmutable::now()->diffInDays($this->pro_until));
    }

    /**
     * Whether the user is in Pro's last PRO_ENDING_SOON_DAYS days.
     */
    public function isProEndingSoon(): bool
    {
        $daysLeft = $this->proDaysLeft();

        return $daysLeft !== null && $daysLeft <= self::PRO_ENDING_SOON_DAYS;
    }

    /**
     * When Pro paid for now starts: now, or when the user's current Pro
     * ends if that is later, so paying early never loses days and months
     * and years stack. Pro then lasts until `$period->after()` this.
     */
    public function nextProStart(): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        return $this->pro_until !== null && $this->pro_until->isAfter($now) ? $this->pro_until : $now;
    }

    /**
     * When the user's Pro would end if they paid for one more period now.
     */
    public function proUntilAfterPaying(BillingPeriod $period): CarbonImmutable
    {
        return $period->after($this->nextProStart());
    }

    /**
     * @return HasMany<ActivityEntry, $this>
     */
    public function activityEntries(): HasMany
    {
        return $this->hasMany(ActivityEntry::class);
    }

    /**
     * @return HasMany<SignInIdentity, $this>
     */
    public function signInIdentities(): HasMany
    {
        return $this->hasMany(SignInIdentity::class);
    }

    /**
     * The login of the user's GitHub identity, or null when they don't sign
     * in with GitHub.
     */
    public function gitHubLogin(): ?string
    {
        return $this->signInIdentities->firstWhere('provider', IdentityProvider::GitHub)?->login;
    }

    /**
     * What the user signs in as, to show beside their name: `@octocat` when
     * they sign in with GitHub, otherwise the email address of another
     * sign-in identity or of their profile.
     */
    public function signInName(): ?string
    {
        $identity = $this->signInIdentities->firstWhere('provider', IdentityProvider::GitHub)
            ?? $this->signInIdentities->first();

        return $identity?->displayName() ?? $this->email;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
