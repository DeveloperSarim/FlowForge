# FlowForge developer guide

How FlowForge is built, run locally, released and extended. For the product overview see the [README](README.md); for end-user installation see the [documentation](https://developersarim.github.io/FlowForge/).

Self-hosted workflow automation (a visual node canvas like n8n or Make) that runs on ordinary PHP shared hosting: Apache or LiteSpeed, MySQL, and **one cron line**. No Docker, Redis, Supervisor or Node process on the server.

What's included:
- **1,690 apps** with 8,700 actions and 800 triggers, each with its real logo.
- **54 ready-made templates.**
- **AI voice agents:** phone agents that answer and make calls through Vapi or ElevenLabs, book appointments and keep call history (see [Voice agents](#voice-agents)).
- **n8n-style editor:** square app nodes, a "What happens next?" picker, and a three-pane step editor (Input, Parameters, Output) where you drag fields to map them.
- **Execute step:** run one step plus everything before it.
- **Overview dashboard** with 7-day metrics.
- **Light theme by default;** dark mode is in Settings → Appearance.

- **Stack:** Laravel 12, Inertia + React 18, React Flow (`@xyflow/react`), Tailwind v4.
- **Queue and cache:** both use the database driver.
- **Background work:** `php artisan schedule:run` once a minute drains the queue with `queue:work --stop-when-empty`.

## Install on shared hosting

1. **Build a release** on your machine. The server needs no Composer or Node.
   ```bash
   composer install && npm ci
   bash scripts/make-release.sh 1.2.0   # version is optional (default: the VERSION file)
   ```
   This writes `dist/flowforge-install-<version>.zip` (fresh installs), `dist/flowforge-update-<version>.zip` (updates, see below) and `dist/extract-flowforge.php`. They contain production `vendor/` and built assets, and no `.env`, install flag, logs or sessions. PHP 8.2+.
2. **Upload the install zip** in your host's File Manager and extract it.
   - If extraction stops part-way (File Managers often time out on ~14,000 files: you'd see `app/`, `config/`, `resources/` but no `public/`, `vendor/` or `artisan`), also upload `dist/extract-flowforge.php` next to the zip and open `https://your-domain/extract-flowforge.php`. It unpacks in batches, then deletes the zip and itself and opens the installer.
   - Best: point the domain's document root at `public/`.
   - If you can only upload into `public_html`, the root `.htaccess` forwards requests into `public/`.
3. **Make these writable:** `storage/`, `bootstrap/cache/` and the app folder itself, since the installer creates `.env` there.
4. **Create the database:** in cPanel → MySQL Databases, create an empty database and a user with all privileges on it.
5. **Run the wizard:** open `https://your-domain/install`. It walks through five steps:
   1. Server checks: PHP 8.2+, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `fileinfo`, `gd`, `zip`, and folder permissions.
   2. Database: tests the connection, writes `.env`, then runs `migrate --force` and the seeder.
   3. Administrator: creates the single admin account and picks the timezone schedules run in.
   4. Email alerts: SMTP details for failure / scheduler-down emails and password-reset codes, with a "Send test email" button. Skippable; change it later in Settings.
   5. Scheduler (required): a **"Set it up for me"** button adds the cron line to the account's crontab when the host allows PHP to do that (other entries are kept); otherwise step-by-step guides for **Hostinger hPanel**, **cPanel**, **DirectAdmin** and **SSH**, with this server's PHP and `artisan` paths filled in. The wizard checks every 5 seconds and only lets you finish once cron has actually run. Finishing switches to `APP_ENV=production` and locks `/install` (the `APP_KEY` generated on first boot is kept, so encrypted settings stay readable).
6. **The cron job** you add in step 5 looks like this (every minute):
   ```
   * * * * * /usr/local/bin/php /home/USER/flowforge/artisan schedule:run >> /dev/null 2>&1
   ```
   **One cron job runs everything:** every schedule, webhook and polling workflow. You never add a cron per workflow. The sidebar shows "Scheduler running" while it is healthy.
   - Hostinger: hPanel → Websites → Dashboard → Advanced → Cron Jobs → type **Custom**, all time fields `*`. If cron's default PHP is too old, use `/opt/alt/php82/usr/bin/php`.
   - cPanel: Advanced → Cron Jobs → Common Settings **Once Per Minute**. Versioned PHP: `/opt/cpanel/ea-php82/root/usr/bin/php`.

Configured `.env` by hand and ran `php artisan migrate --force` yourself? Set `FLOWFORGE_INSTALLED=true` to skip the wizard.

**Updating:** Settings → Updates checks the latest GitHub release (`FLOWFORGE_UPDATE_REPO`, default `DeveloperSarim/FlowForge`). The check uses the API, or the `releases/latest` redirect when a shared host has used up the API's 60 calls an hour. Results are cached 6 hours and refreshed by a daily scheduled check, which feeds the "Update" badge on Settings. On an admin's click, `Updater::download()` first fetches the release's `SHA256SUMS.txt` and `SHA256SUMS.txt.sig` and checks the Ed25519 signature against `config('flowforge.updates.public_key')` (`sodium_crypto_sign_verify_detached`; `paragonie/sodium_compat` covers hosts without ext-sodium). Only then does it stream `flowforge-update-<version>.zip` and compare its SHA-256 with the signed list. A missing or bad signature, a list without the zip, or a mismatch refuses the update and keeps nothing. The manual path is the same pipeline: drop the zip under "Install from a zip file instead" and it uploads in 1.5 MB pieces, so `upload_max_filesize` doesn't matter. From there both paths:
1. Unpack in batches into `storage/app/updates/staging`.
2. Swap the new code in with `public/update-runner.php` (no Laravel, one-time token). The old code moves to `storage/app/updates/backup-<old version>-<time>` and is restored if any move fails.
3. Run migrations on the new code.

`.env`, `storage/` and `bootstrap/cache` are never in an update package, so settings, credentials, workflows and history stay. Older versions won't install over newer ones. Installs older than 1.1.0 (no Updates tab yet) update once with the install zip + `extract-flowforge.php`, which keeps `.env` and `storage/`. Uploading a zip by hand skips the signature on purpose: it's the admin's own file, like code uploaded over FTP. **Releases:** run `bash scripts/make-release.sh x.y.z` (it signs `SHA256SUMS.txt` with the key at `${FLOWFORGE_SIGNING_KEY:-~/.flowforge/release-signing.key}` and stops if the key is missing or doesn't match the public key in config), then upload both zips, `extract-flowforge.php`, `SHA256SUMS.txt` and `SHA256SUMS.txt.sig` from `dist/` to a GitHub release tagged `vx.y.z`. Keep an offline backup of the signing key: without it, installs can't take one-click updates any more. `scripts/make-signing-key.php` makes a new pair (forks set `FLOWFORGE_UPDATE_PUBLIC_KEY`).

**Forgot your password?** "Forgot password?" on the sign-in page emails a 6-digit code (valid 15 minutes, 5 tries, single use). Without email configured, run `php artisan flowforge:reset-password you@example.com` from a terminal.

**Timezone:** Settings → General. Schedules ("every day at 11:00") run in this timezone; timestamps are stored in UTC.

## Live demo mode

Set `FLOWFORGE_DEMO=true` in `.env` for a public demo copy. The sign-in page then shows a visitor login (Settings → Live demo sets it and saves the workflows the demo resets to every hour). Visitors can build and edit workflows and use the AI builder (40 messages an hour), but can't delete, run or switch on workflows, see or add credentials, or change settings: `DemoRestrictions` refuses every write that isn't on its allow-list.

User documentation (installation on hPanel, cPanel and VPS, every feature) is a static site in `docs/`, published with GitHub Pages; open `docs/index.html` locally or host the folder anywhere.

## MCP for AI agents

Claude Code, Cursor, VS Code and any other MCP client can search apps and build, edit, test and run workflows on your FlowForge.

1. Settings → **API & MCP** → *Create token* (shown once; only its hash is stored). The agent acts as you, with exactly your permissions.
2. Connect, e.g. Claude Code:
   ```bash
   claude mcp add --transport http flowforge https://your-domain/api/mcp --header "Authorization: Bearer ff_…"
   ```
   Cursor / VS Code snippets are on the same page.

The server speaks Streamable HTTP (JSON-RPC 2.0 over POST, protocol versions 2025-06-18 / 2025-03-26 / 2024-11-05) at `/api/mcp`, with the same 12 tools as the in-app AI builder and its workflow-building guide as the `instructions`. Over MCP, `delete_workflow` deletes immediately (it is marked destructive so clients ask first). Revoke a token any time on the same page.

## Phones

The whole app works on a phone like a native app: a bottom tab bar (Home, Workflows, AI, Runs, More), bottom-sheet dialogs, a one-pane-at-a-time step editor and 16px fields (no iOS zoom). "Add to Home Screen" opens it full screen with its own icon.

## SEO and agent discovery

- `/robots.txt` and `/sitemap.xml` are generated with the install's own domain. Only `/login` is indexable; every page behind sign-in sends `noindex`.
- `/llms.txt` (summary + how to connect over MCP) and `/llms-full.txt` (building guide, every tool schema and the full app catalog) follow [llmstxt.org](https://llmstxt.org).
- The project site has its own static `docs/llms.txt` and `docs/llms-full.txt` (product summary and full reference). Update them, the README and `docs/index.html` (meta, JSON-LD, changelog) when a feature or version changes.
- Social preview: `public/og-image.png` (1200×630), Open Graph + Twitter card tags, JSON-LD `SoftwareApplication`, favicon set and `site.webmanifest`.

## Team and permissions

Settings → Team (owner and admins) invites people by email; they open the link and choose their own password. Without SMTP the admin copies the link and sends it themselves. Links work for 7 days and only once.

| Role / access | Can do |
| --- | --- |
| Owner | Everything; can't be removed or demoted |
| Admin | Everything, including Settings, Team and Updates |
| Member: Workflows view | See workflows (read-only editor), runs and templates |
| Member: Workflows edit | Create, edit, run and switch workflows on/off; use and add credentials (secrets are never shown) |
| Member: Workflows delete | Also delete workflows and credentials |
| Member: AI builder | Chat with the AI builder, which can only do what the member's workflow access allows |

Access is enforced on the server (`can:` route middleware and the assistant's tools); the UI just hides what isn't allowed. AI chats are private to each person. Failure emails go to the owner and admins.

One install is one organization: workflows, credentials and runs are shared by its team and separated by permissions, not by tenant. To serve several independent clients, give each client its own install (its own database and domain); there is no per-client data isolation inside one install.

**Outbound requests** from anything a user can point somewhere (HTTP Request and app steps, custom apps, OpenAPI import, OAuth token URLs, voice agent webhooks) go through `UrlGuard::protect()`: http/https only, the host is resolved and refused if any address is private (loopback, RFC 1918, CGNAT, IPv6 unique-local) or reserved (link-local and cloud metadata such as 169.254.169.254, multicast, 0.0.0.0/8), the checked IP is pinned with `CURLOPT_RESOLVE`, and every redirect is checked again. `FLOWFORGE_ALLOW_PRIVATE_NETWORK=true` allows private addresses for VPS installs that call their own services (Ollama, LM Studio); metadata and link-local addresses stay blocked. The AI provider's base URL (owner-only setting) and the fixed GitHub/Vapi/ElevenLabs hosts aren't guarded.

## AI builder

Under **AI Builder** in the sidebar (and **Ask AI** in the editor), describe a workflow in plain words — English or Urdu — and the assistant builds it: it searches the app catalog, reads the exact inputs of the actions it needs, saves the workflow, attaches matching credentials, and tells you what's left to fill in. It can also explain and edit existing workflows, test them (only when asked, since tests perform real actions), switch them on or off, and propose deletes (you confirm on a card; nothing is deleted before that).

Providers live in `Providers::ALL`. `custom` is any endpoint the admin sets up: base URL, optional key (an OpenAI-compatible server on the same machine may need none), model, `format` (`openai` chat completions or `anthropic` messages) and an optional label. `Providers::protocol()` picks the wire format and `Providers::configured()` is the single "is AI set up" check. A custom endpoint only gets `reasoning_effort` when the admin picked a level (`effort: none` sends nothing).

While it works, the chat shows each step live ("Searching apps", "Reading LinkedIn actions", "Building…", "Fixing: …"); the progress is streamed as NDJSON and kept on the answer as a collapsible "Worked through N steps" list.

Data between steps is checked when the assistant saves: every `{{ steps.X.field }}` must point at a step that runs earlier and at a field that step returns. `get_app` tells the model each action's outputs (e.g. AI chat actions return `text`), built-in nodes and triggers have closed output lists (hard errors), and for API responses the documented fields plus fields seen in earlier runs are used ("Fix:" warnings the model corrects before replying).

Providers (Settings → AI builder, your own API key, stored encrypted):

| Provider | How it's called | Default model |
| --- | --- | --- |
| DeepSeek | OpenAI-compatible API | `deepseek-chat` |
| Qwen (Alibaba Cloud Model Studio) | OpenAI-compatible API, region selectable | `qwen-plus` |
| OpenAI (ChatGPT) | Chat Completions API | `gpt-5-mini` |
| Anthropic Claude | official `anthropic-ai/sdk` (prompt caching, effort, server-side refusal fallback) | `claude-opus-5-5` |
| Google Gemini | Generative Language API | `gemini-2.5-flash` |

Token use is kept low by design: the 1,000+ app catalog is never sent — the model searches it with a tool and fetches only the inputs it needs; tool results are compact; the system prompt and tool list are static so every provider's prompt cache applies; thinking effort is configurable; one request is capped at 10 tool steps. Each answer shows its token usage (input, cached, output).

## Voice agents

`app/Domain/Voice`. AI phone agents built in FlowForge; the live call (telephony, speech) runs on a provider because shared hosting can't stream audio.

- **Models:** `VoiceAgent` (provider `browser`, `vapi` or `elevenlabs`, credential, phone number, `config` JSON: persona, tone, verbosity, first message, instructions, knowledge, FAQs, booking, collect, voice), `VoiceCall`, `VoiceBooking`.
- **Prompt:** `VoicePrompt` builds the system prompt (persona, phone manners, knowledge, FAQs, booking rules, hang up after goodbye), the `book_appointment` tool and the schema of details to capture. `{{business_name}}` and per-call `{{variables}}` are filled in.
- **Providers** implement `VoiceProvider`. Saving an agent publishes it: Vapi gets an assistant with the booking tool and `endCall`; ElevenLabs gets an agent, a webhook tool and the `end_call` system tool. Both attach the chosen phone number.
- **Events:** providers call `POST /api/voice/{token}` (Vapi tool calls and end-of-call reports, the ElevenLabs webhook tool). Publishing sends an `X-FlowForge-Secret` header (Vapi `server.headers`, ElevenLabs `request_headers`), an HMAC of the agent's token with `APP_KEY` that is never stored or sent to the browser. Once `events_secret_sent` is set, requests without it get 401; agents published before that keep working until their next save. `flowforge:voice-poll` runs every minute and imports finished calls the webhook missed.
- **Browser test calls:** `VoiceService::talk` runs the conversation with the AI builder's model (`LlmClient`) and the booking and `end_call` tools; the page uses the Web Speech API, or ElevenLabs text-to-speech (`/voice/agents/{agent}/speak`) for ElevenLabs agents.
- **Voices:** `/voice/agents/{agent}/voices` lists Vapi's voices or the ElevenLabs voice library and account voices; `/voices/add` tests a library voice with three characters of speech before adding it, because the free ElevenLabs plan can't use library voices through the API.
- **Templates:** `resources/voice-templates/*.json` (persona, sample conversation, booking, knowledge, FAQs, details to capture).
- **Webhooks:** `config.webhooks` ([{url, events: call_ended|booking_created}], max 5) and `config.webhook_secret` (made on first save, kept across saves). `VoiceService::startWorkflows` also calls `VoiceWebhooks::dispatch`, which queues `SendVoiceWebhook` per matching URL (4 tries, backoff 1/5/30 min). Bodies are signed in `X-FlowForge-Signature` (sha256 HMAC of the raw body); the last 20 deliveries are kept in the cache for the studio's log. `POST /voice/agents/{agent}/webhooks/test` sends a sample.
- **Workflows:** `resources/integrations/core/voice.json` has the `voice_call` / `voice_booking` triggers and the `voice_call` handler action; the AI builder and MCP use `create_voice_agent` and `list_voice_agents`.
- **Tests:** `tests/Feature/VoiceTest.php` fakes both providers and the AI model.

## Email alerts

The installer (and Settings → Email & alerts) configures the system SMTP server. FlowForge emails the admin when:
- a production run fails (failing step, error, link to the run) — at most one email per workflow per window (default 15 minutes; suppressed failures are counted in the next email),
- a polling trigger can't reach its app,
- the cron job was down for 10+ minutes (sent when it runs again).

Editor test runs never send alerts. For alerts in Slack/Telegram/anything else, build a workflow with the **Error Trigger** ("When another workflow fails").

## How a run happens

| Trigger | What starts it |
| --- | --- |
| Webhook | `POST/GET /api/webhook/{token}`. Active workflows queue a run. Inactive ones save the request as test data for the editor. `WebhookGuard` refuses bodies over `FLOWFORGE_WEBHOOK_MAX_KB` (413), redacts sensitive header and query values to `[redacted]` before anything is stored, and, when the trigger's "Check incoming requests" (`verify`) or a manifest `signature` is set, checks the sender (401 and no run on failure). Handshakes are answered before the check. "Get a new URL" (`POST /workflows/{id}/webhook-url`) rotates the token. |
| Schedule | A cron expression on the trigger node. It is checked every minute, and a minute guard stops it firing twice. |
| Polling | A manifest's polling endpoint is called every N minutes. The first poll only records a baseline; after that, each new item id starts one run. |

Schedules and polls write their trigger state, the new runs and those runs' queue jobs in one transaction on the workflow's locked row (`PollerService::locked`; the queue is the database driver on the same connection). A crash part-way writes nothing, so an item is picked up on the next poll instead of being lost, and overlapping `schedule:run` calls can't both fire one minute. `Workflow::mergeTriggerState()` always merges into the stored state under a row lock, never into a stale in-memory copy.
| Manual / Test | "Test workflow" in the editor runs **synchronously** and opens the step debugger. |

Every scheduler tick:
1. It records a heartbeat.
2. It enqueues due schedules and polls.
3. It runs `queue:work database --stop-when-empty --max-time=50`.

The worker's `--timeout=600` stays below the database queue's `retry_after` (900) and the worker's overlap lock (15 minutes), so a long job is never handed to a second worker (`ReliabilityTest` checks this). `RunWorkflow` has `tries = 1`: a job whose worker died is failed, not run again.

Runs are stored in `workflow_executions`, with each step's resolved input, output, HTTP request and status. `GraphRunner` saves the steps so far, with the next one as `running`, before each action calls out. If the worker dies mid-request, `RunWorkflow::failed()` marks that step `uncertain` ("may or may not have gone through"), and it is never retried automatically. Credential secrets are masked out of these logs, and large payloads are capped. Runs older than `FLOWFORGE_EXECUTION_RETENTION_DAYS` are pruned daily.

### Graph semantics

- **Shape:** the workflow is a DAG stored as JSON in `workflows.graph`. Saving rejects loops and edges into a trigger.
- **Several triggers:**
  - The main trigger is always `trigger`. More triggers are `trigger_2`, `trigger_3`…, and each starts its own flow.
  - A run records which one fired in `workflow_executions.trigger_node` (null means the main one). `GraphRunner` fires only that trigger and marks the others skipped, so only its flow runs.
  - `trigger_type` is the main trigger's kind; `trigger_types` (`,webhook,schedule,`) lists every kind, for `Workflow::scopeWithTrigger()`.
  - Each trigger has its own state: the main one at the top level of `trigger_state` (as before), the others under `trigger_state.nodes.<id>`. Use `triggerState()` and `mergeTriggerState($patch, $id)`.
  - Webhook triggers answer at `/api/webhook/{token}/{trigger_N}`. `PollerService`, `PollWorkflow`, voice triggers and error triggers work per trigger, and retries keep the trigger.
  - Editor test runs take `node` (run up to it, started by its flow's trigger) or `trigger` (run that whole flow).
- **Order:** nodes run in topological order.
- **When a node runs:** when at least one incoming edge is live. If/Else emits `true` or `false`. A failing Filter emits nothing, which stops that path.
- **Failure:** the first failing step fails the run, and the rest are marked skipped, unless the step says otherwise (below).
- **Step settings** (node `data`, cleaned by `GraphValidator::normalize`): `disabled` skips the step and the path goes on (an If/Else takes "true"); `retries` (1-5) tries it again after 2, 4, 8, 16, 30 s, only when `ActionException::isTemporary()` (no response, 408/425/429, 5xx, SMTP transport errors) and not `mayHaveGoneThrough()`: a POST/PATCH that timed out, lost its connection mid-way or got a 500/502/504 may have done its work already, so it is marked `uncertain` and not repeated (GET/HEAD/PUT/DELETE, connection refused/DNS/TLS failures and 408/425/429/503 are safe). Apps whose manifest has `idempotency_header` (Stripe, PayPal, Mollie, GoCardless, Adyen, Checkout.com) get the same key, an HMAC of the original run id and the node id with `APP_KEY`, on every try and resume of a write, so those writes are retried safely; `on_error: "continue"` records the failure, puts `{error, http_status}` in its output and goes on.
- **Automatic retries** (`workflows.settings.auto_retry`, on unless switched off; `retry_on` is `temporary` (default) or `all`): a production run (webhook, schedule, polling, voice, error) that failed on a temporary error (or on any error with `retry_on: all`) gets `retry_at`, and `RetryExecution` is queued with a delay of 2, 10, 60, 180 and 360 minutes. It creates a run with `retry_of` and `attempt + 1`; `GraphRunner` reuses the steps that succeeded in the failed run (nothing is reused if an output was truncated), so emails and API writes aren't repeated. The failure email and error workflows wait for the last try. `RetryExecution` claims the retry with one conditional UPDATE on `retry_at`, so two copies of the job (or a click on Retry at the same moment) start it once, and a retry by hand cancels the waiting automatic one. Uncertain steps are never retried automatically, not even with `retry_on: all`. Editor tests and manual retries are never retried automatically; a workflow switched off meanwhile isn't retried. Executions → Retry resumes from the failed step by default (`from: start` runs everything again).

### Variables

Any text field can reference earlier steps:

```
{{ steps.trigger.data.email }}        webhook body field
{{ steps.step_2.items[0].id }}        array index
{{ steps.step_2.items.*.id }}         every id (wildcard)
{{ workflow.name }}  {{ execution.id }}  {{ now }}
```

A field that is exactly one `{{ … }}` keeps the value's type (arrays stay arrays). Mixed text interpolates the value as a string. In the editor, click a field, then pick a value under **Data from earlier steps**.

## Integrations: manifests, not classes

Each integration is one JSON or YAML file under `resources/integrations/` (any sub-folder). There are no PHP classes per integration. `WorkflowActionExecutor` reads the manifest and renders the HTTP request:
- `{{ inputs.* }}` is the step's settings, after variables are resolved.
- `{{ credentials.* }}` is the decrypted credential.

```json
{
  "key": "acme",
  "name": "Acme CRM",
  "icon": "https://cdn.simpleicons.org/hubspot",
  "category": "CRM",
  "base_url": "https://api.acme.test/v2",
  "headers": { "Accept": "application/json" },
  "auth": { "type": "api_key", "in": "header", "name": "X-Api-Key" },
  "actions": [{
    "key": "create_contact",
    "name": "Create contact",
    "method": "POST",
    "endpoint": "/contacts",
    "body": { "email": "{{ inputs.email }}", "tags": "{{ inputs.tags }}" },
    "inputs": [
      { "key": "email", "type": "string", "required": true },
      { "key": "tags", "type": "list" }
    ]
  }],
  "triggers": [{
    "key": "new_contact", "name": "New contact", "type": "polling",
    "endpoint": "/contacts", "query": { "sort": "-created" }, "items_path": "data", "id_field": "id",
    "inputs": [{ "key": "poll_interval", "type": "number", "default": 5 }]
  }]
}
```

- **Auth types:** `none`, `oauth2_client_credentials` (machine-to-machine; token fetched and cached automatically), `api_key` (`in: header|query`, or omitted to use `{{ credentials.api_key }}` in the URL), `bearer`, `basic`, `oauth2` (`authorize_url`, `token_url`, `scopes`, `authorize_params`; refresh is automatic), `google_service_account` (signed JWT), `smtp`, `mysql`. A manifest may list several types, e.g. Google Sheets accepts OAuth2 or a service account.
- **Input types:** `string`, `text`, `password`, `number`, `boolean`, `select`, `json`, `code`, `list`, `keyvalue`, `conditions`, `cron` (friendly schedule picker that stores a cron expression).
- **Input options:** `fallback: "credentials.<field>"` fills an empty input from the connected account (see `profile`), `escape: "little_text"` applies LinkedIn's reserved-character escaping and turns `#tags` into hashtags, `show_if: {"other_input": value | [values]}` shows the field only while another input has one of those values.
- **Webhook signatures:** a webhook trigger may declare `signature: {type: header|basic|hmac|stripe|slack, header?, encoding?: hex|base64, prefix?}` plus a password input `signing_secret`. Once the user fills in the secret, `WebhookGuard::verify` checks every request (Stripe and Slack refuse timestamps over 5 minutes old). Used by Stripe, GitHub, Shopify and WooCommerce.
- **Optional action keys:**
  - `query`, `headers`, `body`
  - `body_format` (`json` or `form`)
  - `output_path` (return only this part of the response)
  - `output_fields` (e.g. `{"text": "content.*.text"}`), shown to the AI builder as the action's outputs
  - `output` (closed list of returned keys, for built-in handlers)
  - `error_hints` (`{"403": "what to check"}`, appended to HTTP errors; also allowed at the integration level)
  - `error_path` (fail when this field is set, as with Slack's `ok:false`)
  - `timeout`
  - `outputs` (makes the node a branch)
  - `handler` (built-ins: `if`, `filter`, `json_parse`, `text_format`, `smtp`, `mysql`)
- **Connected account:** an auth spec may declare `profile: {endpoint, store: {field: template}}`. After connecting (and lazily before first use) FlowForge reads that endpoint and saves the fields on the credential, e.g. LinkedIn's `/v2/userinfo` → `person_urn: "urn:li:person:{{ sub }}"`, so "Post as" needs no manual URN.
- **Idempotency:** `idempotency_header` (integration level, e.g. `"Idempotency-Key"`, `"PayPal-Request-Id"`) sends a key that stays the same for every try and resume of a step on POST/PATCH/PUT/DELETE, so the engine can retry those writes after a timeout. Only add it for APIs that de-duplicate on that header.
- **OAuth extras:** `client_auth: "basic"` for providers that want client credentials in a Basic header (Zoom, QuickBooks, Xero…), `token_type` for non-`Bearer` schemes (`Zoho-oauthtoken`), and `{{ credentials.* }}` inside OAuth URLs and scopes for per-region hosts.
- **OpenAPI v3:** drop a spec (`.json` or `.yaml`) into the same folder and every operation becomes an action. Add `x-flowforge: {key, name, icon, category}` to override the defaults. See `resources/integrations/openapi/jsonplaceholder.yaml`.
- **Audit:** `php artisan flowforge:audit [app-or-category] [--warnings] [--live]` dry-runs every action through the real engine against a fake HTTP client (unresolved `{{ }}`, invalid URLs, unused inputs, undefined credential fields, template references) and, with `--live`, calls keyless GET actions for real.
- **Validation:** `php artisan flowforge:integrations` lists everything and reports invalid manifests. Invalid files are skipped, never fatal. The compiled registry is cached in `bootstrap/cache/integrations.php` and rebuilt when any file changes.

### Custom apps

`CustomApps` saves manifests that an install adds itself: the AI builder and MCP agents create them with the `create_custom_app` tool when `search_apps` finds nothing, and Apps → **Add a custom app** imports an OpenAPI document (`POST /apps/custom`). They live in `storage/app/integrations/<key>.json` (`flowforge.custom_integrations_path`), so updates keep them, and `ManifestLoader` reads them after the bundled tree (a custom app can't shadow a bundled one) and marks them `custom`.

- Keys are always `custom_<slug>`; the category is "Custom apps". Saving the same name again updates the app.
- Custom actions must use paths on the app's `base_url`, and each gets `restrict_to_base_url`, so a credential can never be sent to another host. Handlers are not allowed. Auth is limited to none, api_key, bearer, basic, oauth2 and oauth2_client_credentials.
- Validation is the normal schema validation plus those rules; errors go back to the model as text so it can fix the app.
- `DELETE /apps/custom/{key}` refuses while a workflow uses the app.

### The bundled catalog

`resources/integrations/apps/<category>/` holds about 1,690 manifests across 24 categories, including:
- **AI:** OpenAI, Gemini, Mistral, ElevenLabs, Pinecone…
- **Communication:** Gmail, Outlook, Teams, Twilio, WhatsApp…
- **CRM:** HubSpot, Salesforce, Pipedrive, Zoho…
- **Marketing:** Mailchimp, SendGrid, Brevo, Klaviyo…
- **E-commerce and payments:** Shopify, WooCommerce, PayPal, QuickBooks, Xero…
- **Developer, database, project, file and form tools.**

Run `php artisan flowforge:integrations --summary` for the counts per category.

Where an app's endpoints couldn't be confirmed, its manifest ships only base URL + auth. The generated "Make an API call" action still makes it usable. Endpoints were written from the providers' documentation and checked through the engine with faked HTTP; most have **not** been run against live accounts. Test a step before relying on it.

### Logos

`node scripts/build-integration-icons.mjs --favicons` writes `public/integrations/icons/<key>.svg|png`. The output is committed, so the server needs no Node. Each logo comes from the first source that has it:
1. the full-colour [SVG Logos](https://github.com/gilbarbara/logos) set (CC0)
2. [Simple Icons](https://simpleicons.org) in the brand colour (CC0)
3. the app's own favicon at 256px through Google's favicon service
4. a lettered tile

Near-black marks are saved as `.mono.svg` so they invert in dark mode. Logos are trademarks of their owners and are used only to identify each integration.

### Templates

`resources/templates/*.json` contains `{key, name, description, category, featured, graph}`. "Use template" copies the graph into a new workflow. Templates whose apps aren't installed are hidden.

## Code map

```
app/Domain/
  Installer/     EnsureInstalled (gate), SystemValidator, EnvManager, InstallerController
  Workflows/     Models (Workflow, WorkflowExecution), Workflow/Execution/Webhook controllers
  Integrations/  ManifestLoader, IntegrationManifestSchema, OpenApiImporter, VariableResolver,
                 WorkflowActionExecutor, CredentialVault, Handlers/ (Logic, Mail, Database), Credential model
  Engine/        GraphRunner, TopologicalSorter, PollerService, Jobs/ (RunWorkflow, PollWorkflow)
  Voice/         VoiceService, VoicePrompt, Providers/ (Vapi, ElevenLabs), Models (VoiceAgent, VoiceCall, VoiceBooking)
  Assistant/     LlmClient, AssistantAgent, WorkflowTools (AI builder + MCP)
resources/js/
  Components/Canvas/  nodes (Trigger/Action/Branch + deletable edge), NodeCreator, FloatingToolbar
  Components/Drawer/  NodeDetails (Input | Parameters | Output), DynamicForm
  Components/         ui (shadcn-style primitives), DataView (schema/JSON, drag-to-map), ExecutionDebug, ExecutionsChart
  Pages/              Overview, Workflows/{Index,Builder}, Templates, Apps/{Index,Show}, Executions, Credentials, Settings, Install/Wizard, Voice/{Agents,Agent,Calls,Bookings}
resources/integrations/  core/, apps/, openapi/
routes/console.php       the scheduler wiring
```

## Development

```bash
composer install && npm install
npm run dev                      # Vite
(cd public && php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php)
php artisan schedule:run         # one tick; or: php artisan schedule:work
php artisan test
```

Use the plain PHP server, not `php artisan serve --no-reload`. The latter passes its startup environment to its workers, so the `.env` that the installer writes is ignored until a restart.

## Known limits

- Webhook runs start on the next cron tick, so up to about a minute later.
- **Webhook handshakes:** the endpoint answers Meta, Microsoft Graph, Slack, monday.com, Asana and Trello. Zoom's signed URL validation is not handled.
- **OAuth:** supports the authorization-code flow only. Apps that require PKCE (X/Twitter, Etsy, Canva) or client credentials only (Personio, Snov.io) use pasted tokens or were left out.
- **Request bodies:** multipart file uploads aren't supported. Empty JSON objects (`{}`) are sent as `[]`.
- A write that timed out may still have done its work on the app's side. FlowForge doesn't repeat it on its own (except for apps with an idempotency key); a retry by hand can.
- Polling dedupes on the last 1,000 item ids per workflow.

## License

FlowForge is free software: you can redistribute it and/or modify it under the terms of the [GNU Affero General Public License v3.0](LICENSE). If you run a modified version for other people over a network, you must offer them its source code. Bundled third-party code keeps its own licenses (MIT, ISC, OFL, CC0; see `vendor/` and `package.json`).
