<?php

declare(strict_types=1);

return [
    'default' => env('MAUTIC_CONNECTION', 'main'),

    'oauth' => [
        'database_connection' => null,
        'tokens_table' => 'mautic_oauth_tokens',
        'states_table' => 'mautic_oauth_states',
        'locks_table' => 'mautic_oauth_locks',
        'state_ttl_seconds' => 600,
        'expiry_leeway_seconds' => 60,
        'lock_wait_seconds' => 5,
        'lock_lease_seconds' => 30,
        'poll_milliseconds' => 100,
    ],

    'connections' => [
        'main' => [
            // Instance root URL, including a subdirectory if applicable.
            'url' => env('MAUTIC_URL', ''),
            'auth' => 'basic',
            'username' => env('MAUTIC_USERNAME', ''),
            'password' => env('MAUTIC_PASSWORD', ''),
            'timeout' => 10,
            'connect_timeout' => 3,
            // Only opt in for a local development Mautic instance.
            'allow_http' => false,
            // OAuth2 alternative (tokens remain in encrypted database storage):
            // 'auth' => 'oauth2',
            // 'oauth' => [
            //     'client_id' => env('MAUTIC_OAUTH_CLIENT_ID', ''),
            //     'client_secret_env' => 'MAUTIC_OAUTH_CLIENT_SECRET',
            //     'redirect_uri' => env('MAUTIC_OAUTH_REDIRECT_URI', ''),
            //     'scopes' => [],
            // ],
        ],
    ],
];
