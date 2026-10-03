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
    | tools, and is also the most one session's requests take altogether,
    | handshake and OAuth renewal included. Laravel Cloud ends web requests
    | after about 60 seconds, so the call timeout stays under that.
    |
    */

    'downstream' => [
        'connect_timeout' => (float) env('NEXUS_DOWNSTREAM_CONNECT_TIMEOUT', 10),
        'call_timeout' => (float) env('NEXUS_DOWNSTREAM_CALL_TIMEOUT', 55),
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalogs
    |--------------------------------------------------------------------------
    |
    | Every Connection's catalog is refreshed in the background once a day,
    | and when a Star lists tools or prompts from a catalog older than
    | this many minutes. That refresh is queued after the response, so
    | the list itself is served from the catalog as it is.
    |
    */

    'catalogs' => [
        'stale_after_minutes' => (int) env('NEXUS_CATALOG_STALE_AFTER_MINUTES', 360),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Signup is public, so every user's account has limits; how many Stars
    | and Connections it may have come from its plan (below). Calls to a Star
    | are limited per minute for each credential a client uses (each of its
    | tokens), so a runaway agent can't exhaust the user's downstream quotas.
    | Anyone may register an OAuth client with a Star in OAuth mode, so
    | registrations are limited per hour for each IP address. Anyone may ask
    | for a sign-in code by email, so codes are limited per hour for each
    | address and for each IP address, besides a minute's pause between two
    | codes to the same address. Anyone can reach PayMongo's webhook, so its
    | deliveries are limited per minute for each IP address.
    |
    */

    'limits' => [
        'tokens_per_star' => (int) env('NEXUS_TOKENS_PER_STAR', 10),
        'calls_per_minute' => (int) env('NEXUS_CALLS_PER_MINUTE', 120),
        'oauth_registrations_per_hour' => (int) env('NEXUS_OAUTH_REGISTRATIONS_PER_HOUR', 20),
        'email_codes_per_address_per_hour' => (int) env('NEXUS_EMAIL_CODES_PER_ADDRESS_PER_HOUR', 5),
        'email_codes_per_ip_per_hour' => (int) env('NEXUS_EMAIL_CODES_PER_IP_PER_HOUR', 20),
        'paymongo_webhooks_per_minute' => (int) env('NEXUS_PAYMONGO_WEBHOOKS_PER_MINUTE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | What each plan includes (App\Enums\Plan) and what Pro costs. A user is
    | on Pro while their `pro_until` is in the future, and on Free otherwise.
    | A null limit means unlimited. Prices are in centavos (₱499.00 is
    | 49900). The Free limits can be changed for tests and self-hosting;
    | limits stop additions only and never delete or disable anything.
    |
    */

    'plans' => [
        'free' => [
            'stars' => (int) env('NEXUS_FREE_STARS', 2),
            'connections' => (int) env('NEXUS_FREE_CONNECTIONS', 10),
            'tool_calls_per_week' => (int) env('NEXUS_FREE_TOOL_CALLS_PER_WEEK', 3000),
        ],
        'pro' => [
            'stars' => null,
            'connections' => null,
            'tool_calls_per_week' => null,
            'prices' => [
                'month' => 49900,
                'year' => 499900,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Nexus bills in Philippine pesos, so billing dates (when Pro ends, when
    | a payment was made) show in Philippine time, and the weekly tool-call
    | limit resets in it.
    |
    */

    'billing' => [
        'timezone' => 'Asia/Manila',
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
    | Contact and legal pages
    |--------------------------------------------------------------------------
    |
    | The address people write to about their account, payments, refunds
    | and privacy. The Terms, Privacy and Refund pages show it. Those pages
    | are a draft, and say so at the top, until the owner has reviewed them
    | with counsel and turned `reviewed` on.
    |
    */

    'contact_email' => env('NEXUS_CONTACT_EMAIL', 'hello@example.com'),

    'legal' => [
        'reviewed' => (bool) env('NEXUS_LEGAL_REVIEWED', false),
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
