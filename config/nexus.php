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

    /*
    |--------------------------------------------------------------------------
    | Dev Sign-in
    |--------------------------------------------------------------------------
    |
    | A local-only shortcut that signs in as one of the seeded users, "Dev
    | User" or "Second User", without a GitHub OAuth app. It only exists when
    | the app environment is "local" and this flag is on; everywhere else its
    | route is a 404. Run `php artisan db:seed` to create the two users.
    |
    */

    'dev_sign_in' => (bool) env('NEXUS_DEV_SIGN_IN', false),

];
