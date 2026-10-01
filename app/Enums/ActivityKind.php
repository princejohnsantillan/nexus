<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a client asked a Star for, as an activity entry records it.
 */
enum ActivityKind: string
{
    /** A `tools/call`. */
    case Tool = 'tool';

    /** A `prompts/get`. */
    case Prompt = 'prompt';
}
