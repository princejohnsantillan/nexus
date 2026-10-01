<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a user signs in to a gallery connector's server.
 */
enum SignInMethod: string
{
    /** The user pastes a token of their own, which Nexus sends as a header. */
    case Token = 'token';

    /** The user signs in on the service's own consent screen. */
    case OAuth = 'oauth';

    public function label(string $service): string
    {
        return match ($this) {
            self::Token => __('Your own token'),
            self::OAuth => __('Sign in with :service', ['service' => $service]),
        };
    }

    public function description(string $service): string
    {
        return match ($this) {
            self::Token => __('Nexus acts as you, with exactly the access you give the token. No OAuth app needed.'),
            self::OAuth => __('Approve Nexus on :service\'s own sign-in page.', ['service' => $service]),
        };
    }
}
