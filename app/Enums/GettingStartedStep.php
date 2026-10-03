<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The steps of the getting-started checklist on the Stars page, in the
 * order a new user takes them.
 */
enum GettingStartedStep: string
{
    /** The user has a Connection. */
    case ConnectServer = 'connect-server';

    /** The user has a Star. */
    case CreateStar = 'create-star';

    /** One of the user's Stars has a token, a connected app, or uses its signed URL. */
    case SetUpClient = 'set-up-client';

    /** One of the user's Stars has been called. */
    case ReceiveFirstCall = 'receive-first-call';

    public function label(): string
    {
        return match ($this) {
            self::ConnectServer => __('Connect a server'),
            self::CreateStar => __('Create a Star'),
            self::SetUpClient => __('Set up a client'),
            self::ReceiveFirstCall => __('First call received'),
        };
    }
}
