<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\ConnectionTool;

/**
 * What a tool may do to data, as its server declared in its annotations at
 * the last refresh. A Star's Tools page groups each Connection's tools by
 * it, in the order of the cases: the safest first and the unknown last.
 */
enum ToolRisk: string
{
    /** The server declares it read-only (`readOnlyHint: true`). */
    case ReadOnly = 'read_only';

    /**
     * It may change data but isn't declared destructive: `readOnlyHint` is
     * false, or only `destructiveHint: false` is stated, since a tool that
     * doesn't say it is read-only is taken not to be.
     */
    case Writes = 'writes';

    /** The server declares it may destroy data (`destructiveHint: true`) and doesn't call it read-only. */
    case Destructive = 'destructive';

    /** The server states neither `readOnlyHint` nor `destructiveHint`. */
    case NotDeclared = 'not_declared';

    /**
     * The group a tool belongs in. A read-only hint wins, as the MCP
     * specification only reads the destructive hint for tools that aren't
     * read-only.
     */
    public static function of(ConnectionTool $tool): self
    {
        return match (true) {
            $tool->read_only === true => self::ReadOnly,
            $tool->destructive === true => self::Destructive,
            $tool->read_only === null && $tool->destructive === null => self::NotDeclared,
            default => self::Writes,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ReadOnly => __('Read-only'),
            self::Writes => __('Writes'),
            self::Destructive => __('Destructive'),
            self::NotDeclared => __('Not declared'),
        };
    }
}
