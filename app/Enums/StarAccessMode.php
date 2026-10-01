<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How clients authenticate to a Star.
 */
enum StarAccessMode: string
{
    /** Clients send one of the Star's tokens as `Authorization: Bearer nxs_…`. */
    case Token = 'token';

    public function label(): string
    {
        return match ($this) {
            self::Token => __('Bearer token'),
        };
    }
}
