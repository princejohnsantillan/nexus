<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Star;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * The database cache refused one of a Star's lists.
 *
 * The query's error and its bindings would carry the list, made from its
 * Connections' catalogs, which come from their servers, so neither is kept:
 * the message names the Star, the list and the SQLSTATE only, and no
 * previous exception is chained.
 */
final class StarListsNotCached extends RuntimeException
{
    public static function for(Star $star, string $list, QueryException $exception): self
    {
        $state = $exception->errorInfo[0] ?? null;

        return new self(sprintf(
            'Nexus could not cache the %s list of Star %d (SQLSTATE %s).',
            $list,
            $star->id,
            is_string($state) ? $state : 'unknown',
        ));
    }
}
