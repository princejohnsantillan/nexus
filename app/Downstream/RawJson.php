<?php

declare(strict_types=1);

namespace App\Downstream;

use stdClass;

/**
 * Reads parts of a JSON document as the exact text they were sent as.
 *
 * Decoding JSON and encoding it again changes it: arrays turn `{}` into
 * `[]`, and floats round long numbers and can't hold ones as large as
 * `1e400` at all. Nexus stores and passes on what servers send, so it cuts
 * values out of the original text instead. Anything that isn't valid JSON
 * of the expected shape gives null.
 */
final class RawJson
{
    private const string WHITESPACE = " \t\n\r";

    /**
     * The text of one member of a JSON object, or null when the document
     * isn't an object or has no such member. The last of duplicate members
     * wins, as with json_decode().
     */
    public static function member(string $json, string $key): ?string
    {
        $found = null;

        foreach (self::members($json) ?? [] as [$name, $start, $end]) {
            if ($name === $key) {
                $found = substr($json, $start, $end - $start);
            }
        }

        return $found;
    }

    /**
     * The JSON object with the value of one member replaced by the given
     * JSON text, and the rest of the document exactly as it was, or null
     * when the document isn't an object or has no such member. Duplicates
     * of the member are all replaced.
     */
    public static function withMember(string $json, string $key, string $value): ?string
    {
        $replaced = null;

        foreach (array_reverse(self::members($json) ?? []) as [$name, $start, $end]) {
            if ($name === $key) {
                $replaced = substr_replace($replaced ?? $json, $value, $start, $end - $start);
            }
        }

        return $replaced;
    }

    /**
     * The JSON object with one member set to the given JSON text: replaced
     * where the object has it (every duplicate of it), or else added at the
     * end, with the rest of the document exactly as it was; null when the
     * document isn't an object.
     */
    public static function put(string $json, string $key, string $value): ?string
    {
        $members = self::members($json);

        if ($members === null) {
            return null;
        }

        if (in_array($key, array_column($members, 0), true)) {
            return self::withMember($json, $key, $value);
        }

        $member = json_encode($key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).':'.$value;

        if ($members === []) {
            return substr_replace($json, $member, self::skipWhitespace($json, self::skipWhitespace($json, 0) + 1), 0);
        }

        return substr_replace($json, ','.$member, $members[array_key_last($members)][2], 0);
    }

    /**
     * The name of each member of a JSON object, with the offsets where its
     * value starts and ends, or null when the document isn't an object.
     *
     * @return list<array{mixed, int, int}>|null
     */
    private static function members(string $json): ?array
    {
        $offset = self::skipWhitespace($json, 0);

        if (($json[$offset] ?? '') !== '{') {
            return null;
        }

        $offset = self::skipWhitespace($json, $offset + 1);

        if (($json[$offset] ?? '') === '}') {
            return [];
        }

        $members = [];

        while (true) {
            $keyEnd = ($json[$offset] ?? '') === '"' ? self::skipString($json, $offset) : null;

            if ($keyEnd === null) {
                return null;
            }

            $name = json_decode(substr($json, $offset, $keyEnd - $offset));
            $offset = self::skipWhitespace($json, $keyEnd);

            if (($json[$offset] ?? '') !== ':') {
                return null;
            }

            $valueStart = self::skipWhitespace($json, $offset + 1);
            $valueEnd = self::skipValue($json, $valueStart);

            if ($valueEnd === null) {
                return null;
            }

            $members[] = [$name, $valueStart, $valueEnd];
            $offset = self::skipWhitespace($json, $valueEnd);

            if (($json[$offset] ?? '') === '}') {
                return $members;
            }

            if (($json[$offset] ?? '') !== ',') {
                return null;
            }

            $offset = self::skipWhitespace($json, $offset + 1);
        }
    }

    /**
     * The text of each element of a JSON array, or null when the document isn't an array.
     *
     * @return list<string>|null
     */
    public static function elements(string $json): ?array
    {
        $offset = self::skipWhitespace($json, 0);

        if (($json[$offset] ?? '') !== '[') {
            return null;
        }

        $offset = self::skipWhitespace($json, $offset + 1);

        if (($json[$offset] ?? '') === ']') {
            return [];
        }

        $elements = [];

        while (true) {
            $end = self::skipValue($json, $offset);

            if ($end === null) {
                return null;
            }

            $elements[] = substr($json, $offset, $end - $offset);
            $offset = self::skipWhitespace($json, $end);

            if (($json[$offset] ?? '') === ']') {
                return $elements;
            }

            if (($json[$offset] ?? '') !== ',') {
                return null;
            }

            $offset = self::skipWhitespace($json, $offset + 1);
        }
    }

    /**
     * Whether the document is a JSON object.
     */
    public static function isObject(string $json): bool
    {
        return json_decode($json) instanceof stdClass;
    }

    private static function skipWhitespace(string $json, int $offset): int
    {
        return $offset + strspn($json, self::WHITESPACE, $offset);
    }

    /**
     * The offset just past the value that starts at the offset, or null when no value starts there.
     */
    private static function skipValue(string $json, int $offset): ?int
    {
        $first = $json[$offset] ?? '';

        if ($first === '"') {
            return self::skipString($json, $offset);
        }

        if ($first === '{' || $first === '[') {
            return self::skipContainer($json, $offset);
        }

        $length = strcspn($json, self::WHITESPACE.',:]}', $offset);

        return $length === 0 ? null : $offset + $length;
    }

    /**
     * The offset just past the string that starts at the offset.
     */
    private static function skipString(string $json, int $offset): ?int
    {
        $length = strlen($json);
        $offset++;

        while (true) {
            $offset += strcspn($json, '"\\', $offset);

            if ($offset >= $length) {
                return null;
            }

            if ($json[$offset] === '"') {
                return $offset + 1;
            }

            $offset += 2;
        }
    }

    /**
     * The offset just past the object or array that starts at the offset.
     */
    private static function skipContainer(string $json, int $offset): ?int
    {
        $length = strlen($json);
        $depth = 0;

        while ($offset < $length) {
            $offset += strcspn($json, '"{}[]', $offset);

            if ($offset >= $length) {
                return null;
            }

            if ($json[$offset] === '"') {
                $offset = self::skipString($json, $offset);

                if ($offset === null) {
                    return null;
                }

                continue;
            }

            $depth += $json[$offset] === '{' || $json[$offset] === '[' ? 1 : -1;
            $offset++;

            if ($depth === 0) {
                return $offset;
            }
        }

        return null;
    }
}
