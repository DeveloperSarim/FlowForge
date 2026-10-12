# FlowForge

Self-hosted workflow automation (a visual node canvas like n8n or Make) that runs on ordinary PHP shared hosting: Apache or LiteSpeed, MySQL, and **one cron line**. No Docker, Redis, Supervisor or Node process on the server.

What's included:
- **586 apps** with 2,761 actions and 344 triggers, each with its real logo.
- **50 ready-made templates.**
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
   bash scripts/make-release.sh      # → dist/flowforge-<date>.zip (PHP 8.2+, ~19 MB)
   ```
   The zip has production `vendor/` and built assets, and no `.env`, install flag, logs or sessions.
2. **Upload the zip** in your host's File Manager and extract it.
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
   5. Scheduler (required): step-by-step guides for **Hostinger hPanel**, **cPanel**, **DirectAdmin** and **SSH**, with this server's PHP and `artisan` paths filled in. The wizard checks every 5 seconds and only lets you finish once cron has actually run. Finishing switches to `APP_ENV=production` and locks `/install` (the `APP_KEY` generated on first boot is kept, so encrypted settings stay readable).
6. **The cron job** you add in step 5 looks like this (every minute):
   ```
   * * * * * /usr/local/bin/php /home/USER/flowforge/artisan schedule:run >> /dev/null 2>&1
   ```
   **One cron job runs everything:** every schedule, webhook and polling workflow. You never add a cron per workflow. The sidebar shows "Scheduler running" while it is healthy.
   - Hostinger: hPanel → Websites → Dashboard → Advanced → Cron Jobs → type **Custom**, all time fields `*`. If cron's default PHP is too old, use `/opt/alt/php82/usr/bin/php`.
   - cPanel: Advanced → Cron Jobs → Common Settings **Once Per Minute**. Versioned PHP: `/opt/cpanel/ea-php82/root/usr/bin/php`.

Configured `.env` by hand and ran `php artisan migrate --force` yourself? Set `FLOWFORGE_INSTALLED=true` to skip the wizard.

**Forgot your password?** "Forgot password?" on the sign-in page emails a 6-digit code (valid 15 minutes, 5 tries, single use). Without email configured, run `php artisan flowforge:reset-password you@example.com` from a terminal.

**Timezone:** Settings → General. Schedules ("every day at 11:00") run in this timezone; timestamps are stored in UTC.

## AI builder

Under **AI Builder** in the sidebar (and **Ask AI** in the editor), describe a workflow in plain words — English or Urdu — and the assistant builds it: it searches the app catalog, reads the exact inputs of the actions it needs, saves the workflow, attaches matching credentials, and tells you what's left to fill in. It can also explain and edit existing workflows, test them (only when asked, since tests perform real actions), switch them on or off, and propose deletes (you confirm on a card; nothing is deleted before that).

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

## Email alerts

The installer (and Settings → Email & alerts) configures the system SMTP server. FlowForge emails the admin when:
- a production run fails (failing step, error, link to the run) — at most one email per workflow per window (default 15 minutes; suppressed failures are counted in the next email),
- a polling trigger can't reach its app,
- the cron job was down for 10+ minutes (sent when it runs again).

Editor test runs never send alerts. For alerts in Slack/Telegram/anything else, build a workflow with the **Error Trigger** ("When another workflow fails").

## How a run happens

| Trigger | What starts it |
| --- | --- |
| Webhook | `POST/GET /api/webhook/{token}`. Active workflows queue a run. Inactive ones save the request as test data for the editor. |
| Schedule | A cron expression on the trigger node. It is checked every minute, and a minute guard stops it firing twice. |
| Polling | A manifest's polling endpoint is called every N minutes. The first poll only records a baseline; after that, each new item id starts one run. |
| Manual / Test | "Test workflow" in the editor runs **synchronously** and opens the step debugger. |

Every scheduler tick:
1. It records a heartbeat.
2. It enqueues due schedules and polls.
3. It runs `queue:work database --stop-when-empty --max-time=50`.

Runs are stored in `workflow_executions`, with each step's resolved input, output, HTTP request and status. Credential secrets are masked out of these logs, and large payloads are capped. Runs older than `FLOWFORGE_EXECUTION_RETENTION_DAYS` are pruned daily.

### Graph semantics

- **Shape:** the workflow is a DAG stored as JSON in `workflows.graph`. Saving rejects loops and edges into the trigger.
- **Order:** nodes run in topological order.
- **When a node runs:** when at least one incoming edge is live. If/Else emits `true` or `false`. A failing Filter emits nothing, which stops that path.
- **Failure:** the first failing step fails the run, and the rest are marked skipped.

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
- **Input options:** `fallback: "credentials.<field>"` fills an empty input from the connected account (see `profile`), `escape: "little_text"` applies LinkedIn's reserved-character escaping and turns `#tags` into hashtags.
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
- **OAuth extras:** `client_auth: "basic"` for providers that want client credentials in a Basic header (Zoom, QuickBooks, Xero…), `token_type` for non-`Bearer` schemes (`Zoho-oauthtoken`), and `{{ credentials.* }}` inside OAuth URLs and scopes for per-region hosts.
- **OpenAPI v3:** drop a spec (`.json` or `.yaml`) into the same folder and every operation becomes an action. Add `x-flowforge: {key, name, icon, category}` to override the defaults. See `resources/integrations/openapi/jsonplaceholder.yaml`.
- **Audit:** `php artisan flowforge:audit [app-or-category] [--warnings] [--live]` dry-runs every action through the real engine against a fake HTTP client (unresolved `{{ }}`, invalid URLs, unused inputs, undefined credential fields, template references) and, with `--live`, calls keyless GET actions for real.
- **Validation:** `php artisan flowforge:integrations` lists everything and reports invalid manifests. Invalid files are skipped, never fatal. The compiled registry is cached in `bootstrap/cache/integrations.php` and rebuilt when any file changes.

### The bundled catalog

`resources/integrations/apps/<category>/` holds 586 manifests across 22 categories, including:
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
resources/js/
  Components/Canvas/  nodes (Trigger/Action/Branch + deletable edge), NodeCreator, FloatingToolbar
  Components/Drawer/  NodeDetails (Input | Parameters | Output), DynamicForm
  Components/         ui (shadcn-style primitives), DataView (schema/JSON, drag-to-map), ExecutionDebug, ExecutionsChart
  Pages/              Overview, Workflows/{Index,Builder}, Templates, Apps/{Index,Show}, Executions, Credentials, Settings, Install/Wizard
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
- Steps don't retry, and there is no "continue on fail". The first failure stops the run.
- Single admin user; workflows are not owned per user.
- Polling dedupes on the last 1,000 item ids per workflow.
