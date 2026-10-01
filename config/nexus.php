<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Outbound guard
    |--------------------------------------------------------------------------
    |
    | Users can point Nexus at any URL, so every request through the HTTP
    | client must go to a public HTTPS address, with DNS resolved once and
    | pinned for the request, and redirects are never followed. These
    | switches are honoured only in the local environment, for testing
    | against a local MCP server; everywhere else both are always on.
    |
    */

    'outbound' => [
        'block_private_networks' => (bool) env('NEXUS_BLOCK_PRIVATE_NETWORKS', true),
        'require_https' => (bool) env('NEXUS_REQUIRE_HTTPS', true),
    ],

];
