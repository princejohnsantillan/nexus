<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\ConnectionTool;

/**
 * Whether a tool the user hasn't switched themselves is on in a Star. It
 * applies to every such tool, including ones a server adds later, and reads
 * the tool's annotations as they are now.
 */
enum NewToolPolicy: string
{
    /** Tools the server declares read-only are on; every other tool is off. */
    case ReadOnly = 'read_only';

    /** Every tool is on. */
    case All = 'all';

    /** Every tool is off. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::ReadOnly => __('Read-only tools on'),
            self::All => __('All tools on'),
            self::None => __('All tools off'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ReadOnly => __('Only tools the server marks read-only are on. The safe default.'),
            self::All => __('Every tool is on, including ones that can change or delete data.'),
            self::None => __('Every tool is off until you switch it on.'),
        };
    }

    /**
     * Which tools of a Connection added to the Star start on, in words:
     * "Read-only tools start on in Work."
     */
    public function startsOnIn(string $star): string
    {
        return match ($this) {
            self::ReadOnly => __('Read-only tools start on in :star.', ['star' => $star]),
            self::All => __('Every tool starts on in :star.', ['star' => $star]),
            self::None => __('Every tool starts off in :star.', ['star' => $star]),
        };
    }

    /**
     * Whether this policy turns the tool on. Under the read-only policy only a
     * tool whose server declares `readOnlyHint: true` is on, so a tool that
     * stops being read-only, or never says, is off.
     */
    public function enables(ConnectionTool $tool): bool
    {
        return match ($this) {
            self::ReadOnly => $tool->read_only === true,
            self::All => true,
            self::None => false,
        };
    }
}
