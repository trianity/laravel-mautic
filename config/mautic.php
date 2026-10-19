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

    'identity' => [
        'database_connection' => null,
        'mappings_table' => 'mautic_contact_mappings',
    ],

    'outbox' => [
        'database_connection' => null,
        'commands_table' => 'mautic_outbox_commands',
        'locks_table' => 'mautic_outbox_locks',
        'audits_table' => 'mautic_outbox_audits',
        'connection_blocks_table' => 'mautic_outbox_connection_blocks',
        'queue' => null,
        'lease_seconds' => 30,
        'operation_budget_seconds' => 20,
        'max_attempts' => 5,
        'maximum_delay_seconds' => 300,
    ],

    'inbox' => [
        'database_connection' => null,
        'deliveries_table' => 'mautic_webhook_deliveries',
        'events_table' => 'mautic_webhook_events',
        'locks_table' => 'mautic_webhook_locks',
        'queue' => null,
        'maximum_body_bytes' => 1_048_576,
        'lease_seconds' => 30,
    ],

    'reconciliation' => [
        // Keep this equal to identity.database_connection for atomic mapping repair audits.
        'database_connection' => null,
        'checkpoints_table' => 'mautic_reconciliation_checkpoints',
        'observations_table' => 'mautic_reconciliation_observations',
        'findings_table' => 'mautic_reconciliation_findings',
        'audits_table' => 'mautic_reconciliation_audits',
        'page_size' => 100,
        'minimum_page_interval_seconds' => 1,
        'maximum_pages_per_run' => 1,
        'request_reservation_seconds' => 30,
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
            // Opt-in webhook ingress (the secret value remains outside config cache):
            // 'webhook' => [
            //     'secret_env' => 'MAUTIC_WEBHOOK_SECRET',
            //     'allowed_events' => [
            //         'mautic.lead_post_save_update',
            //         'mautic.lead_post_delete',
            //     ],
            // ],
        ],
    ],
];
