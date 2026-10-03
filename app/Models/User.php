<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IdentityProvider;
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
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property int|null $github_id
 * @property string|null $github_login
 * @property string|null $avatar_url
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereGithubId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereGithubLogin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_id' => 'integer',
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
     * Whether the user has as many Connections as `nexus.limits.connections_per_user` allows.
     */
    public function hasReachedConnectionLimit(): bool
    {
        return $this->connections()->count() >= config()->integer('nexus.limits.connections_per_user');
    }

    /**
     * @return HasMany<Star, $this>
     */
    public function stars(): HasMany
    {
        return $this->hasMany(Star::class);
    }

    /**
     * Whether the user has as many Stars as `nexus.limits.stars_per_user` allows.
     */
    public function hasReachedStarLimit(): bool
    {
        return $this->stars()->count() >= config()->integer('nexus.limits.stars_per_user');
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
