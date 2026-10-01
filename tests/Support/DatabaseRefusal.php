<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Makes the database refuse writes, as a broken constraint would, on SQLite
 * and on Postgres alike.
 */
final class DatabaseRefusal
{
    /**
     * Refuse every new tool with SQLSTATE 23000.
     */
    public static function newTools(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION refuse_tools() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'refused' USING ERRCODE = '23000'; END \$\$");
            DB::unprepared('CREATE TRIGGER refuse_tools BEFORE INSERT ON connection_tools FOR EACH ROW EXECUTE FUNCTION refuse_tools()');

            return;
        }

        DB::unprepared("CREATE TRIGGER refuse_tools BEFORE INSERT ON connection_tools BEGIN SELECT RAISE(ABORT, 'refused'); END");
    }
}
