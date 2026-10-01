<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How clients authenticate to a Star. A Star accepts only its current
 * mode's credential.
 */
enum StarAccessMode: string
{
    /** Clients send one of the Star's tokens as `Authorization: Bearer nxs_…`. */
    case Token = 'token';

    /** Clients use the Star's signed URL, which carries its own credential. */
    case SignedUrl = 'signed_url';

    public function label(): string
    {
        return match ($this) {
            self::Token => __('Bearer token'),
            self::SignedUrl => __('Signed URL'),
        };
    }

    /**
     * A short explanation of the mode, for choosing one.
     */
    public function description(): string
    {
        return match ($this) {
            self::Token => __('Clients send a token you create for each of them in a header. You can revoke each token on its own.'),
            self::SignedUrl => __('One secret URL that works by itself, for clients that only take a URL. Anyone with it can use the Star; rotate it to stop the old one working.'),
        };
    }
}
