<?php

declare(strict_types=1);

namespace App\Connectors;

/**
 * A tool on a connector's server that says which account Nexus is signed
 * in as, such as GitHub's `get_me`. Nexus calls it with no arguments after
 * loading the tools, and reads the account from one field of its result.
 */
final readonly class ConnectorProfileTool
{
    /**
     * @param  string  $name  The tool's name on the server.
     * @param  string  $field  Where the result names the account, as a dot path into its JSON (e.g. `login`).
     */
    public function __construct(
        public string $name,
        public string $field,
    ) {}
}
