<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why Nexus refused a call, as far as its activity entry and the Star as it
 * is now can tell. An entry doesn't record why it was refused, so a tool
 * that is on now was either switched on since or refused for another
 * reason, such as arguments that weren't an object.
 */
enum DenialReason: string
{
    /** The client sent no name. */
    case NoName = 'no_name';

    /** None of the Star's Connections had a tool or prompt by that name. */
    case Unknown = 'unknown';

    /** The Star has the tool or prompt, and it is switched off now. */
    case SwitchedOff = 'switched_off';

    /** The Star had it, but it is on now or the Star no longer has it. */
    case Other = 'other';

    /**
     * The row label for a call of this kind refused for this reason.
     */
    public function label(ActivityKind $kind): string
    {
        return match ($this) {
            self::NoName => __('Denied · no name'),
            self::Unknown => $kind === ActivityKind::Prompt ? __('Denied · unknown prompt') : __('Denied · unknown tool'),
            self::SwitchedOff => $kind === ActivityKind::Prompt ? __('Denied · prompt off') : __('Denied · tool off'),
            self::Other => __('Denied'),
        };
    }
}
