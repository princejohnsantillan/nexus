<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How Nexus signs in to a Connection's server.
 */
enum ConnectionAuthType: string
{
    /** The server needs no sign-in. */
    case None = 'none';

    /** Nexus sends one header, such as `Authorization: Bearer …`, with every request. */
    case Header = 'header';

    public function label(): string
    {
        return match ($this) {
            self::None => __('No auth'),
            self::Header => __('Header'),
        };
    }
}
