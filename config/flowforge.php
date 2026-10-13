<?php

return [
    // Skip the /install wizard (for hand-configured installs). Normally the storage/.installed flag is used.
    'installed' => (bool) env('FLOWFORGE_INSTALLED', false),

    'integrations_path' => resource_path('integrations'),

    'execution_retention_days' => (int) env('FLOWFORGE_EXECUTION_RETENTION_DAYS', 30),

    'http_timeout' => (int) env('FLOWFORGE_HTTP_TIMEOUT', 30),

    // Public live demo (app/Domain/Demo): a visitor login on the sign-in page, restricted actions, hourly reset.
    'demo' => (bool) env('FLOWFORGE_DEMO', false),

    // Folder the in-app updater replaces code in (tests point it at a temp copy). Null = the app itself.
    'update_root' => null,

    // Where Settings → Updates looks for new releases (forks can point this at their own repository).
    'updates' => [
        'repo' => env('FLOWFORGE_UPDATE_REPO', 'DeveloperSarim/FlowForge'),
    ],

    // Step inputs/outputs larger than this (bytes of JSON) are truncated in execution logs.
    'max_log_bytes' => 64 * 1024,
];
