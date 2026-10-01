<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A connector's JSON file or logo in `resources/connectors` is missing,
 * malformed or breaks the schema. The message names the file and the problem.
 */
final class InvalidConnectorDefinition extends RuntimeException {}
