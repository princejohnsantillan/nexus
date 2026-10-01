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
    | Downstream servers
    |--------------------------------------------------------------------------
    |
    | How long Nexus waits for a Connection's MCP server, in seconds. The
    | connect timeout covers opening the connection and the handshake; the
    | call timeout covers every other request, such as listing or calling
    | tools. Laravel Cloud ends web requests after about 60 seconds, so the
    | call timeout stays under that.
    |
    */

    'downstream' => [
        'connect_timeout' => (float) env('NEXUS_DOWNSTREAM_CONNECT_TIMEOUT', 10),
        'call_timeout' => (float) env('NEXUS_DOWNSTREAM_CALL_TIMEOUT', 55),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Signup is public, so every user's account has limits. Calls to a Star
    | are limited per minute for each credential a client uses (each of its
    | tokens), so a runaway agent can't exhaust the user's downstream quotas.
    |
    */

    'limits' => [
        'connections_per_user' => (int) env('NEXUS_CONNECTIONS_PER_USER', 25),
        'stars_per_user' => (int) env('NEXUS_STARS_PER_USER', 10),
        'tokens_per_star' => (int) env('NEXUS_TOKENS_PER_STAR', 10),
        'calls_per_minute' => (int) env('NEXUS_CALLS_PER_MINUTE', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity
    |--------------------------------------------------------------------------
    |
    | How many days Nexus keeps each activity entry. A daily scheduled prune
    | removes entries older than that, so storage stays small.
    |
    */

    'activity' => [
        'retention_days' => (int) env('NEXUS_ACTIVITY_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connectors
    |--------------------------------------------------------------------------
    |
    | The OAuth app this deployment registered for each gallery connector,
    | if any, keyed by connector. Each `resources/connectors/{key}.json` gets
    | its own pair of variables, NEXUS_{KEY}_CLIENT_ID and
    | NEXUS_{KEY}_CLIENT_SECRET, with dashes in the key as underscores.
    | Credentials never live in the JSON files, which are public.
    |
    */

    'connectors' => array_merge(...array_map(function (string $file): array {
        $key = basename($file, '.json');
        $prefix = 'NEXUS_'.strtoupper(str_replace('-', '_', $key));

        return [$key => [
            'client_id' => env($prefix.'_CLIENT_ID'),
            'client_secret' => env($prefix.'_CLIENT_SECRET'),
        ]];
    }, glob(resource_path('connectors/*.json')) ?: [])),

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

    /*
    |--------------------------------------------------------------------------
    | Credential encryption
    |--------------------------------------------------------------------------
    |
    | Every user's credentials are encrypted with that user's own data key,
    | and data keys are stored wrapped by this master key (32 random bytes,
    | base64-encoded), so the database alone reveals nothing. Generate one
    | with `php artisan nexus:master-key`. Without a valid master key Nexus
    | refuses to encrypt or decrypt anything.
    |
    */

    'encryption' => [
        'master_key' => env('NEXUS_MASTER_KEY'),
    ],

];
