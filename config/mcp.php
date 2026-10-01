<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | Where MCP clients that register themselves with a Star in OAuth mode
    | may ask Nexus to send the user back after they approve. A redirect
    | URI must use HTTPS, or plain HTTP on a loopback address (localhost,
    | 127.0.0.1 or [::1], any port), as command-line clients like Claude
    | Code and Codex receive the code on their own machine.
    |
    */

    'redirect_domains' => [
        'https://',
        'http://localhost',
        'http://127.0.0.1',
        'http://[::1]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Desktop clients such as Cursor and VS Code receive the code on a
    | private-use URI scheme (RFC 8252) instead. A comma-separated list in
    | NEXUS_OAUTH_CUSTOM_SCHEMES replaces these.
    |
    */

    'custom_schemes' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('NEXUS_OAUTH_CUSTOM_SCHEMES', 'cursor,vscode,vscode-insiders,windsurf,zed')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Unused: each Star in OAuth mode is its own issuer,
    | `{APP_URL}/oauth/stars/{star}`, which Nexus publishes itself rather
    | than through laravel/mcp's single-issuer OAuth routes.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    |
    | Here you may configure the limits enforced during tool search. The max
    | number of tool calls limits how many tools search requests can call
    | while the maximum output bytes value will limit the result sizes.
    |
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

];
