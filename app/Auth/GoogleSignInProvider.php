<?php

declare(strict_types=1);

namespace App\Auth;

use Laravel\Socialite\Two\GoogleProvider;

/**
 * Socialite's Google driver as Nexus signs in with it: the default scopes
 * (openid, profile and email) and Google's account chooser every time, so a
 * person signed in to several Google accounts picks which one to use.
 */
class GoogleSignInProvider extends GoogleProvider
{
    /**
     * The custom parameters to be sent with the request.
     *
     * @var array<string, string>
     */
    protected $parameters = ['prompt' => 'select_account'];

    /**
     * Google sign-in is offered only when this deployment has a Google OAuth
     * client, so a local setup without one still works.
     */
    public static function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }
}
