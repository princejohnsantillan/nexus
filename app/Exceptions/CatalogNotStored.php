<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Connection;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * The database refused a Connection's freshly loaded catalog.
 *
 * The query's error and its bindings would carry the tools' names and
 * descriptions, which come from the server, so neither is kept: the message
 * names the Connection and the SQLSTATE only, and no previous exception is
 * chained.
 */
final class CatalogNotStored extends RuntimeException
{
    public static function for(Connection $connection, QueryException $exception): self
    {
        $state = $exception->errorInfo[0] ?? null;

        return new self(sprintf(
            'Nexus could not store the catalog of Connection %d (SQLSTATE %s).',
            $connection->id,
            is_string($state) ? $state : 'unknown',
        ));
    }
}
