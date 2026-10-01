<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How Nexus gets an OAuth client for a connector's server.
 */
enum ClientRegistration: string
{
    /** The server lets clients register themselves (a client metadata document or dynamic registration). */
    case Automatic = 'automatic';

    /**
     * The server only accepts OAuth apps registered in its developer console.
     * The deployment can configure one app for everyone; otherwise each user
     * registers their own.
     */
    case PreRegistered = 'pre_registered';
}
