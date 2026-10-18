<?php

declare(strict_types=1);

return [
    'default' => env('MAUTIC_CONNECTION', 'main'),

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
        ],
    ],
];
