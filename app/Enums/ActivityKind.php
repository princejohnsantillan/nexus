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

    public function label(): string
    {
        return match ($this) {
            self::Tool => __('Tool'),
            self::Prompt => __('Prompt'),
        };
    }

    /**
     * What the client did, as an entry's details name it.
     */
    public function callLabel(): string
    {
        return match ($this) {
            self::Tool => __('Tool call'),
            self::Prompt => __('Prompt fetch'),
        };
    }
}
