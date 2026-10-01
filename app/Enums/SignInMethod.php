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

    /**
     * @param  bool  $ownApp  Whether OAuth goes through an OAuth app the user registers themselves.
     */
    public function label(string $service, bool $ownApp = false): string
    {
        return match (true) {
            $this === self::Token => __('Your own token'),
            $ownApp => __('Your own OAuth app'),
            default => __('Sign in with :service', ['service' => $service]),
        };
    }

    /**
     * @param  bool  $ownApp  Whether OAuth goes through an OAuth app the user registers themselves.
     */
    public function description(string $service, bool $ownApp = false): string
    {
        return match (true) {
            $this === self::Token => __('Nexus acts as you, with exactly the access you give the token. No OAuth app needed.'),
            $ownApp => __('Register an OAuth app on :service, enter its client ID and secret, then approve Nexus on :service\'s own sign-in page.', ['service' => $service]),
            default => __('Approve Nexus on :service\'s own sign-in page.', ['service' => $service]),
        };
    }
}
