<?php

return [
    // Skip the /install wizard (for hand-configured installs). Normally the storage/.installed flag is used.
    'installed' => (bool) env('FLOWFORGE_INSTALLED', false),

    'integrations_path' => resource_path('integrations'),

    'execution_retention_days' => (int) env('FLOWFORGE_EXECUTION_RETENTION_DAYS', 30),

    'http_timeout' => (int) env('FLOWFORGE_HTTP_TIMEOUT', 30),

    // Step inputs/outputs larger than this (bytes of JSON) are truncated in execution logs.
    'max_log_bytes' => 64 * 1024,
];
