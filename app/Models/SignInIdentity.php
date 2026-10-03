<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IdentityProvider;
use Carbon\CarbonImmutable;
use Database\Factories\SignInIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way a user signs in: an account on a provider, identified by the
 * provider's id for it, which belongs to exactly one user. Signing in finds
 * the user by the identity; one nobody has creates a new account.
 *
 * An identity is only ever added to an existing user from Settings while
 * they're signed in, never by matching an email address: the same address
 * on two providers may belong to two people. Deleting the user deletes
 * their identities.
 *
 * @property int $id
 * @property int $user_id
 * @property IdentityProvider $provider
 * @property string $provider_user_id
 * @property string $login
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 *
 * @method static \Database\Factories\SignInIdentityFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SignInIdentity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SignInIdentity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SignInIdentity query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SignInIdentity whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SignInIdentity whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SignInIdentity whereLogin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SignInIdentity whereProvider($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SignInIdentity whereProviderUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SignInIdentity whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SignInIdentity whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'provider', 'provider_user_id', 'login'])]
class SignInIdentity extends Model
{
    /** @use HasFactory<SignInIdentityFactory> */
    use HasFactory;

    /**
     * The identity a provider vouched for, if anyone has it.
     */
    public static function findFor(IdentityProvider $provider, string $providerUserId): ?self
    {
        return self::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IdentityProvider::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What the identity signs in as, the way people recognise it: `@octocat`
     * for a GitHub login, and the email address for the others.
     */
    public function displayName(): string
    {
        return $this->provider === IdentityProvider::GitHub ? '@'.$this->login : $this->login;
    }
}
