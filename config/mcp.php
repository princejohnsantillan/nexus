<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | Where MCP clients registering with an OAuth-mode vault may send users
    | back to. Clients are public apps on many domains (claude.ai, loopback
    | ports for CLIs), so any is allowed; each one still needs the vault
    | owner's approval on Nexus's consent screen.
    |
    */

    'redirect_domains' => [
        '*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Desktop clients that call back through their own URI schemes (RFC 8252).
    |
    */

    'custom_schemes' => [
        'cursor',
        'vscode',
        'vscode-insiders',
        'windsurf',
        'zed',
    ],

    'authorization_server' => null,

];
