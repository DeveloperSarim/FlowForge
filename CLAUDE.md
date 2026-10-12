# FlowForge: notes for Claude

Self-hosted workflow automation (n8n-style canvas) that must run on **PHP shared hosting**: Apache/LiteSpeed, MySQL, and one cron line (`* * * * * php artisan schedule:run`). No Docker, Redis, Supervisor, daemons or Node on the server. Queue and cache use the database driver; `schedule:run` starts `queue:work --stop-when-empty` every minute.

The owner tests in Chrome at `http://127.0.0.1:8123`, writes in Roman Urdu, and deploys to Hostinger hPanel / cPanel. Reply in Roman Urdu/English.

## Stack

Laravel 12 (11.x was blocked by a security advisory), PHP 8.2+ (`config.platform.php` is pinned to 8.2.0, so don't let the lock drift to Symfony 8 / PHP 8.4), Inertia v2 + React 18 (Inertia v3 needs React 19), Vite 7, Tailwind v4 with CSS-variable tokens in `resources/css/app.css` (light default, `.dark` class), Lucide icons, `@xyflow/react`, `anthropic-ai/sdk` for Claude.

## Commands

```bash
php artisan test                                   # feature tests, must stay green
vendor/bin/pint                                    # PHP formatting
npm run build                                      # frontend (also run before releases)
php artisan flowforge:integrations --refresh --summary   # rebuild manifest cache, counts per category
php artisan flowforge:audit [app-or-category] [--warnings] [--live]   # dry-run every action; must report 0 errors
node scripts/build-integration-icons.mjs --favicons # logos for new apps (public/integrations/icons)
bash scripts/make-release.sh                       # dist/flowforge-<date>.zip for shared hosting
php artisan flowforge:reset-password you@example.com
```

Local server (`artisan serve` leaks the shell env into the app, so use plain `php -S`):
```bash
cd public && env -i PATH="$PATH" HOME="$HOME" php -S 127.0.0.1:8123 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```
macOS has no `timeout` command. Local throwaway credentials live in `storage/app/local-dev-install.txt` (git-ignored); never paste them anywhere.

## Layout

- `app/Domain/Integrations`: manifest engine. `IntegrationManifestSchema` (its docblock is the manifest contract), `ManifestLoader` (compiled cache in `bootstrap/cache/integrations/`, self-healing), `WorkflowActionExecutor` (renders HTTP requests from manifests), `VariableResolver` (`{{ steps.x.y }}`), `CredentialVault` (auth types, OAuth2/PKCE/client credentials), `IntegrationAuditor`.
- `app/Domain/Engine`: `GraphRunner` (DAG execution), `PollerService` (schedules + polling, evaluated in `SystemSettings::timezone()`), jobs.
- `app/Domain/Workflows`: models, controllers, `GraphValidator`, `TemplateLibrary` (`resources/templates/*.json`).
- `app/Domain/Assistant`: AI builder. `LlmClient` (DeepSeek, Qwen, OpenAI, Claude, Gemini), `AssistantAgent` (bounded tool loop, streamed progress), `WorkflowTools` (tools + `buildGraph` validation).
- `app/Domain/System`: encrypted `SystemSettings`, `Notifier` (failure / scheduler-down emails).
- `app/Domain/Installer`: `/install` wizard (requirements → MySQL → admin + timezone → SMTP → verified cron). `CronInstaller` is the best-effort "Set it up for me" (crontab via `Process`; tests use `Process::fake`, never touch the real crontab).
- `resources/integrations/**`: ~1190 JSON/YAML manifests (+ OpenAPI specs). `resources/js`: Pages, Components (`ui.jsx` primitives incl. `Combobox`), `Drawer/` (node editor, `DynamicForm`, `ScheduleField`).

## Rules that are easy to break

- **No PHP class per integration.** New behaviour goes into the manifest schema + executor, documented in the schema docblock and README. After touching manifests: `flowforge:integrations --refresh` and `flowforge:audit` (0 errors), then `build-integration-icons.mjs`.
- **Tell the AI what actions return.** Give HTTP actions `output_fields` (e.g. `{"text": "choices.0.message.content"}`) and built-ins a closed `output` list. `WorkflowTools::buildGraph` checks every `{{ steps.X.field }}` against them; wrong references were the cause of real broken workflows.
- **Never make users type IDs they don't know.** Use the auth `profile` lookup + input `fallback` (LinkedIn "Post as", Instagram account), sensible defaults (`me`), `Combobox` instead of long native selects, `ScheduleField` instead of raw cron. Non-technical users are the audience.
- **APP_KEY is never rotated after install**: `SystemSettings` and credentials are encrypted with it. Secrets (API keys, SMTP passwords, tokens) never go to the browser; send `has_key` / `has_password` instead.
- Timestamps are stored in UTC; only schedules and display use the configured timezone.
- AI token budget: tool outputs stay compact, the system prompt is static per install (prompt caching), max 10 tool steps. Deletes by the assistant need the user's confirmation card.
- The chat endpoint streams NDJSON (`Accept: application/x-ndjson`) and must still work when a host buffers output (events then arrive at the end).
- Release zips must not contain `.env`, `storage/.installed`, logs, sessions or `local-dev-install.txt`; `make-release.sh` handles this. Webhook runs wait for the next cron tick (≤ 1 minute).
