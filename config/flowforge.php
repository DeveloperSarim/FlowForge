<?php

return [
    // Skip the /install wizard (for hand-configured installs). Normally the storage/.installed flag is used.
    'installed' => (bool) env('FLOWFORGE_INSTALLED', false),

    'integrations_path' => resource_path('integrations'),
    // Custom apps made in the app or by the AI builder; in storage so updates never touch them.
    'custom_integrations_path' => storage_path('app/integrations'),

    'execution_retention_days' => (int) env('FLOWFORGE_EXECUTION_RETENTION_DAYS', 30),

    'http_timeout' => (int) env('FLOWFORGE_HTTP_TIMEOUT', 30),

    // Largest request body (KB) /api/webhook and /api/voice accept; bigger ones get HTTP 413.
    'webhook_max_kb' => (int) env('FLOWFORGE_WEBHOOK_MAX_KB', 5120),

    // Let steps and webhooks call this server or your private network (localhost, 10.x, 192.168.x), e.g. Ollama
    // on a VPS. Off by default (SSRF). Cloud metadata / link-local addresses stay blocked either way (UrlGuard).
    'allow_private_network' => (bool) env('FLOWFORGE_ALLOW_PRIVATE_NETWORK', false),

    // Public live demo (app/Domain/Demo): a visitor login on the sign-in page, restricted actions, hourly reset.
    'demo' => (bool) env('FLOWFORGE_DEMO', false),

    // Folder the in-app updater replaces code in (tests point it at a temp copy). Null = the app itself.
    'update_root' => null,

    // Where Settings → Updates looks for new releases (forks can point this at their own repository).
    'updates' => [
        'repo' => env('FLOWFORGE_UPDATE_REPO', 'DeveloperSarim/FlowForge'),
        // Ed25519 public key (base64) that must have signed a release's SHA256SUMS.txt for one-click updates.
        // Forks sign with their own key (scripts/make-signing-key.php) and set this alongside the repo.
        'public_key' => env('FLOWFORGE_UPDATE_PUBLIC_KEY', 'D72pCONFcYy2vT8BA5iyWSzcVVH8N1KfXExKg44izSM='),
    ],

    // Step inputs/outputs larger than this (bytes of JSON) are truncated in execution logs.
    'max_log_bytes' => 64 * 1024,
];
