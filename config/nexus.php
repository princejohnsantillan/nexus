<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-user limits
    |--------------------------------------------------------------------------
    |
    | Signup is public, so every account gets hard caps from day one. They
    | double as the meters a future billing plan would read.
    |
    */

    'limits' => [
        'vaults_per_user' => (int) env('NEXUS_MAX_VAULTS', 10),
        'connections_per_user' => (int) env('NEXUS_MAX_CONNECTIONS', 25),
        'tokens_per_vault' => (int) env('NEXUS_MAX_TOKENS_PER_VAULT', 10),
        'calls_per_minute' => (int) env('NEXUS_CALLS_PER_MINUTE', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Downstream MCP servers
    |--------------------------------------------------------------------------
    */

    'downstream' => [
        // Seconds to wait for a downstream tool call. claude.ai gives up at 240s.
        'timeout' => (float) env('NEXUS_DOWNSTREAM_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound request guard
    |--------------------------------------------------------------------------
    |
    | Users can point a connection at any URL, so every outbound request is
    | checked: it must resolve to public IP addresses only, and the resolved
    | address is pinned for the request so DNS cannot change in between.
    | Turn these off only for local development against localhost servers.
    |
    */

    'outbound' => [
        'block_private_networks' => (bool) env('NEXUS_BLOCK_PRIVATE_NETWORKS', true),
        'require_https' => (bool) env('NEXUS_REQUIRE_HTTPS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credential encryption
    |--------------------------------------------------------------------------
    |
    | Each user's credentials are encrypted with that user's own data key.
    | Data keys are stored wrapped by a master key: "local" wraps with
    | NEXUS_MASTER_KEY, "kms" wraps with an AWS KMS key.
    |
    */

    'encryption' => [
        'driver' => env('NEXUS_KEY_DRIVER', 'local'),

        'local' => [
            'master_key' => env('NEXUS_MASTER_KEY'),
        ],

        'kms' => [
            'key_id' => env('NEXUS_KMS_KEY_ID'),
            'region' => env('NEXUS_KMS_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Connector apps
    |--------------------------------------------------------------------------
    |
    | Some official servers (Slack, GitHub) only accept OAuth apps registered
    | in their developer consoles. Register one per deployment and set its
    | credentials here, and users only have to sign in. Without one, each
    | user is asked to bring their own app. Callback: {APP_URL}/oauth/callback
    |
    */

    'connectors' => [
        'slack' => [
            'client_id' => env('NEXUS_SLACK_CLIENT_ID'),
            'client_secret' => env('NEXUS_SLACK_CLIENT_SECRET'),
        ],
        'github' => [
            'client_id' => env('NEXUS_GITHUB_CLIENT_ID'),
            'client_secret' => env('NEXUS_GITHUB_CLIENT_SECRET'),
        ],
        'gmail' => [
            'client_id' => env('NEXUS_GMAIL_CLIENT_ID'),
            'client_secret' => env('NEXUS_GMAIL_CLIENT_SECRET'),
        ],
    ],

    'logs' => [
        'retention_days' => (int) env('NEXUS_LOG_RETENTION_DAYS', 30),
    ],

    'auth' => [
        'providers' => ['github', 'google'],
    ],

];
