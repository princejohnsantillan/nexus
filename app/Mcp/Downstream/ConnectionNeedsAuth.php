<?php

namespace App\Mcp\Downstream;

use App\Models\Connection;
use RuntimeException;

/**
 * The connection's credentials are missing, expired or rejected, and only the
 * owner signing in again can fix it.
 */
class ConnectionNeedsAuth extends RuntimeException
{
    public function __construct(public readonly Connection $connection, string $reason = '')
    {
        parent::__construct($reason !== '' ? $reason : "The [{$connection->name}] connection needs to be signed in again.");
    }
}
