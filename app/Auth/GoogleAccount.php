<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use UnexpectedValueException;

/**
 * The Google account a person just signed in with: Google's subject id
 * (`sub`), which never changes, and the profile Google shares with the
 * openid, profile and email scopes.
 */
final readonly class GoogleAccount
{
    private function __construct(
        public string $id,
        public string $email,
        public string $name,
        public ?string $avatarUrl,
    ) {}

    /**
     * @throws UnexpectedValueException when Google sent no subject id or email address
     */
    public static function from(SocialiteUser $googleUser): self
    {
        $id = $googleUser->getId();
        $email = $googleUser->getEmail();

        if (blank($id) || $email === null || $email === '') {
            throw new UnexpectedValueException('Google returned a profile without an id or an email address.');
        }

        $name = $googleUser->getName();

        return new self(
            id: $id,
            email: $email,
            name: $name === null || $name === '' ? Str::before($email, '@') : Str::limit($name, 255, ''),
            avatarUrl: $googleUser->getAvatar(),
        );
    }
}
