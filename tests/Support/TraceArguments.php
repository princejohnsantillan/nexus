<?php

declare(strict_types=1);

namespace Tests\Support;

use Throwable;

/**
 * Searches the arguments recorded in an exception's stack trace, and in the
 * traces of the exceptions it wraps, the way an error reporter would see them.
 */
final class TraceArguments
{
    public static function contain(Throwable $exception, string $needle): bool
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            foreach ($current->getTrace() as $frame) {
                if (self::valueContains($frame['args'] ?? [], $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function valueContains(mixed $value, string $needle): bool
    {
        if (is_string($value)) {
            return str_contains($value, $needle);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::valueContains($item, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
