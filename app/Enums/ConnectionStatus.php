<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a Connection's server answered its last catalog refresh.
 */
enum ConnectionStatus: string
{
    /** Saved, but its tools have not been loaded yet. */
    case Pending = 'pending';

    /** Its tools loaded at the last refresh. */
    case Connected = 'connected';

    /** The server refused Nexus's sign-in; only the owner can fix it. */
    case NeedsAuth = 'needs_auth';

    /** The last refresh failed for another reason. */
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Connected => __('Connected'),
            self::NeedsAuth => __('Needs sign-in'),
            self::Error => __('Error'),
        };
    }

    /**
     * The colour of this status's Flux badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::Connected => 'green',
            self::NeedsAuth => 'amber',
            self::Error => 'red',
        };
    }
}
