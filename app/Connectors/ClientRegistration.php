<?php

namespace App\Connectors;

/**
 * How Nexus gets an OAuth client for a connector's server.
 */
enum ClientRegistration: string
{
    /** The server lets clients register themselves (CIMD or dynamic registration). */
    case Automatic = 'automatic';

    /**
     * The server only accepts clients registered in its developer console.
     * The deployment can configure one app for everyone; otherwise each user
     * brings their own.
     */
    case PreRegistered = 'pre_registered';
}
