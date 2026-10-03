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

    /** Nexus refused the call without forwarding it: the Star has nothing on by that name, or the call had no name or arguments that aren't an object. */
    case Denied = 'denied';

    /** The server took longer than the call timeout. */
    case Timeout = 'timeout';

    /** The server refused the Connection's sign-in. */
    case NeedsAuth = 'needs_auth';

    /** Nexus didn't forward the call: the account had used its plan's tool calls for the week. */
    case Limited = 'limited';

    public function label(): string
    {
        return match ($this) {
            self::Ok => __('OK'),
            self::Error => __('Error'),
            self::Denied => __('Denied'),
            self::Timeout => __('Timed out'),
            self::NeedsAuth => __('Needs sign-in'),
            self::Limited => __('Weekly limit'),
        };
    }

    /**
     * The colour of this status's Flux badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::Ok => 'green',
            self::Error => 'red',
            self::Denied => 'zinc',
            self::Timeout => 'orange',
            self::NeedsAuth, self::Limited => 'amber',
        };
    }
}
