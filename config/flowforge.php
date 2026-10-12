<?php

return [
    // Skip the /install wizard (for hand-configured installs). Normally the storage/.installed flag is used.
    'installed' => (bool) env('FLOWFORGE_INSTALLED', false),

    'integrations_path' => resource_path('integrations'),

    'execution_retention_days' => (int) env('FLOWFORGE_EXECUTION_RETENTION_DAYS', 30),

    'http_timeout' => (int) env('FLOWFORGE_HTTP_TIMEOUT', 30),

    // CodeCanyon licensing (see app/Domain/License). The server holds the Envato token and the signing key.
    'license' => [
        'server' => 'https://license.sarimtools.com',
        'path' => null, // default storage/app/license.json
    ],

    // Folder the in-app updater replaces code in (tests point it at a temp copy). Null = the app itself.
    'update_root' => null,

    // Step inputs/outputs larger than this (bytes of JSON) are truncated in execution logs.
    'max_log_bytes' => 64 * 1024,
];
