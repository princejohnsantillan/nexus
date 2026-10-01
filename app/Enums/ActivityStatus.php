<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a call through a Star ended, as an activity entry records it.
 */
enum ActivityStatus: string
{
    /** The server answered. */
    case Ok = 'ok';

    /** The call failed, or the server answered with a tool error (`isError`). */
    case Error = 'error';

    /** The Star has nothing on by that name, so Nexus refused the call. */
    case Denied = 'denied';

    /** The server took longer than the call timeout. */
    case Timeout = 'timeout';

    /** The server refused the Connection's sign-in. */
    case NeedsAuth = 'needs_auth';
}
