# FlowForge: notes for Claude

Open-source (AGPL-3.0) self-hosted workflow automation (n8n-style canvas) that must run on **PHP shared hosting**: Apache/LiteSpeed, MySQL, and one cron line (`* * * * * php artisan schedule:run`). No Docker, Redis, Supervisor, daemons or Node on the server. Queue and cache use the database driver; `schedule:run` starts `queue:work --stop-when-empty` every minute.

Most users deploy to Hostinger hPanel or cPanel shared hosting and aren't technical, so the UI must stay simple and guided.

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
bash scripts/make-release.sh 1.2.0                 # dist/flowforge-install-1.2.0.zip + flowforge-update-1.2.0.zip (bumps VERSION)
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
- `resources/integrations/**`: ~1690 JSON/YAML manifests (+ OpenAPI specs). `resources/js`: Pages, Components (`ui.jsx` primitives incl. `Combobox`), `Drawer/` (node editor, `DynamicForm`, `ScheduleField`).

## Rules that are easy to break

- **No PHP class per integration.** New behaviour goes into the manifest schema + executor, documented in the schema docblock and DEVELOPMENT.md. After touching manifests: `flowforge:integrations --refresh` and `flowforge:audit` (0 errors), then `build-integration-icons.mjs`.
- **Tell the AI what actions return.** Give HTTP actions `output_fields` (e.g. `{"text": "choices.0.message.content"}`) and built-ins a closed `output` list. `WorkflowTools::buildGraph` checks every `{{ steps.X.field }}` against them; wrong references were the cause of real broken workflows.
- **Never make users type IDs they don't know.** Use the auth `profile` lookup + input `fallback` (LinkedIn "Post as", Instagram account), sensible defaults (`me`), `Combobox` instead of long native selects, `ScheduleField` instead of raw cron. Non-technical users are the audience.
- **Permissions**: `User::PERMISSIONS` + roles owner/admin/member, enforced by `can:` route middleware (gates in `AppServiceProvider`) and `WorkflowTools::call` for the AI. Every new route goes into the right `can:` group in `routes/web.php`; the UI hides actions via `useCan()` but that is never the protection. AI chats are scoped to their user (`AiConversation::resolveRouteBinding`).
- **MCP** (`/api/mcp`, `McpController`) reuses `WorkflowTools` and `AssistantAgent::workflowGuide()`: change a tool or the guide once and both the AI builder and MCP clients get it. Auth is `ApiToken` (sha256 only) via `AuthenticateApiToken`; never add a tool that bypasses `WorkflowTools::call`'s permission check.
- **Public files** (`robots.txt`, `sitemap.xml`, `llms.txt`, `llms-full.txt`) are routes in `SeoController`, not files in `public/` (a static file would shadow the route and lose the domain). Only `/login` is indexable.
- **Phones are a first-class target** (it should feel like an app): bottom tab bar + top bar under `lg`, dialogs become bottom sheets, the step editor shows one pane at a time. No page may scroll sideways at 390px: grid columns use `minmax(0,1fr)`, chip rows use `no-scrollbar overflow-x-auto`, and text fields are 16px on phones (`text-base sm:text-sm`) so iOS doesn't zoom. Mind `env(safe-area-inset-*)` and use `dvh` for full-height screens.
- **APP_KEY is never rotated after install**: `SystemSettings` and credentials are encrypted with it. Secrets (API keys, SMTP passwords, tokens) never go to the browser; send `has_key` / `has_password` instead.
- Timestamps are stored in UTC; only schedules and display use the configured timezone.
- AI token budget: tool outputs stay compact, the system prompt is static per install (prompt caching), max 10 tool steps. Deletes by the assistant need the user's confirmation card.
- The chat endpoint streams NDJSON (`Accept: application/x-ndjson`) and must still work when a host buffers output (events then arrive at the end).
- In-app updates: `App\Domain\System\Updater` (chunked upload → batch extract → `public/update-runner.php` swap with backup/rollback → `finish` migrates on the new code). Bump `VERSION` (and the version badge in `README.md`) for every release; update packages must never contain `.env`, `storage/` or `bootstrap/cache`. Tests point `flowforge.update_root` at a temp folder; never run the runner against the repo.
- Release zips must not contain `.env`, `storage/.installed`, logs, sessions or `local-dev-install.txt`; `make-release.sh` handles this. Webhook runs wait for the next cron tick (≤ 1 minute).
- **Voice agents** (`app/Domain/Voice`): the live call runs on Vapi or ElevenLabs (shared hosting can't stream audio). FlowForge stores agents (`config` JSON), calls and bookings; `VoicePrompt` builds the system prompt and the `book_appointment` tool; providers implement `VoiceProvider`. Providers call `/api/voice/{token}` (Vapi tool calls + end-of-call reports, ElevenLabs webhook tool); a minute cron poll catches up on finished calls. "Browser" agents talk through the Web Speech API with the AI builder's model (`VoiceService::talk`). Templates live in `resources/voice-templates/*.json`; the `voice_call` / `voice_booking` triggers and the `voice_call` handler are in `resources/integrations/core/voice.json`.
- **Demo mode** (`app/Domain/Demo`): on with `FLOWFORGE_DEMO=true`. The visitor account may only do what `DemoRestrictions::ALLOWED` lists (deny by default); a new write route stays blocked for the demo user unless it's added there deliberately. Hourly `flowforge:demo-reset` restores the saved snapshot.
- **Open source** under AGPL-3.0 (`LICENSE`); there is no license check. User docs live in `docs/` (static HTML for GitHub Pages, screenshots in `assets/img`, excluded from release zips); the product README uses `.github/readme/`, and `DEVELOPMENT.md` is the developer guide; update its changelog for each release. When a feature or version changes, also update `docs/llms.txt`, `docs/llms-full.txt` and the docs meta/JSON-LD (these are what AI assistants and search engines read).
