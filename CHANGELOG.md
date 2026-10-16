# Changelog

All notable changes to FlowForge. Versions follow [semantic versioning](https://semver.org).

## [1.7.0] - 2026-10-16

Security and reliability fixes from a production-readiness review.

- **No lost or doubled events:** schedules and polls save what they've seen and start their runs in one database transaction, so a crash part-way loses nothing and the next poll picks the items up. Two overlapping cron ticks can't fire a schedule twice, and a finished poll no longer overwrites newer trigger state.
- **No repeated orders after a timeout:** each step is saved before it calls out. If the worker dies mid-request, the run shows that step as "may have gone through". A POST/PATCH that timed out or got a 500/502/504 is not retried automatically, even with "retry every failure". GET requests, failed connections and 429/503 are still retried. Stripe, PayPal, Mollie, GoCardless, Adyen and Checkout.com get an idempotency key that stays the same for every try, so their writes retry safely.
- **Retries run once:** an automatic retry is claimed atomically, and retrying a run by hand cancels the waiting automatic retry.
- **Webhook security:**
  - The Webhook trigger can check every request: secret header, basic auth, HMAC signature (GitHub, Shopify, WooCommerce), or Stripe or Slack signatures. Old Stripe and Slack requests are refused, so recorded requests can't be replayed. Stripe, GitHub, Shopify and WooCommerce triggers have a Signing secret field.
  - "Get a new URL" replaces a leaked webhook URL.
  - Passwords, tokens and signatures in request headers and query strings are saved as `[redacted]` in run history and test data, including existing runs. Workflows that read `{{ steps.trigger.headers.authorization }}` now get `[redacted]`: use "Check incoming requests" instead.
  - Bodies over 5 MB are refused (`FLOWFORGE_WEBHOOK_MAX_KB`).
- **Voice agents:** Vapi and ElevenLabs now send a secret header that FlowForge checks. An agent gets it the next time it is saved.
- **No requests to internal addresses (SSRF):** HTTP and app steps, custom apps, OpenAPI imports, OAuth token URLs and voice webhooks can't reach private networks, localhost or cloud metadata addresses, also through redirects. VPS installs that call their own services (Ollama, LM Studio) set `FLOWFORGE_ALLOW_PRIVATE_NETWORK=true`.
- **Signed updates:** one-click updates install only releases whose checksum list is signed with the FlowForge release key and matches the download. A missing or bad signature or checksum stops the update. Uploading a zip by hand works as before. Two uploads at once no longer delete each other's files.
- **Installation:**
  - A fresh install runs in production mode with debug off from the first page.
  - Installing over HTTPS turns on secure cookies.
- **Secrets stay out of AI chats:** the AI Builder and MCP clients see `[secret is set]` instead of passwords and signing secrets typed into steps, and saving keeps the real value.
- **Faster run counts:** runs have an index on their date. Deleting a custom app checks only the workflows that mention it.
- **Continuous integration:** GitHub Actions runs the tests on PHP 8.2 to 8.4 on every push, plus code style, the integration audit, the frontend build and vulnerability checks.

## [1.6.3] - 2026-10-16

- Settings → Updates shows the release notes ("What's new") formatted: headings, bold text, lists, code and the downloads table, instead of raw Markdown.

## [1.6.2] - 2026-10-16

- AI Builder answers are formatted: code in boxes with the language and a Copy button, tables, headings, quotes and links (also in the editor's Ask AI panel).
- Voice agent webhooks: send every finished call or new booking to your own URLs (Make, Zapier, n8n, your server) as signed JSON, straight from the agent's Webhooks section, with a test button, retries after 1, 5 and 30 minutes, and a log of recent deliveries.

## [1.6.1] - 2026-10-16

- Automatic retries (like Zapier's Autoreplay): a run that fails because an app is down, slow or rate-limited is retried from the failed step after 2 minutes, 10 minutes, 1 hour, 3 hours and 6 hours. Steps that already worked aren't repeated, and the failure email and error workflows wait for the last try. On by default, with an Auto-retry switch for each workflow in the workflows list and at the top of the editor. Under ⋯ → Retries and errors a workflow can also retry every failure, not only temporary ones.
- Step settings (open a step, "When this step runs"): try again right away up to 5 times on temporary errors, keep going with the next steps when it fails, or turn the step off (also from the canvas toolbar).
- AI builder: a custom provider for any OpenAI- or Anthropic-compatible API (OpenRouter, Groq, Together, Ollama, LM Studio, LiteLLM…), with your own base URL, optional API key, model and thinking effort (or "Don't send").
- Fixed: saving AI settings without a base URL failed with a server error.
- Executions → Retry now resumes from the failed step; "from the start" runs every step again. Runs show which try they are and when the next automatic try is.

## [1.6.0] - 2026-10-15

- About 500 new apps (now 1,690): AI, communication and regional SMS, social media, marketing and email verification, SEO, CRM, helpdesks, developer tools, security, payments (including Middle East, Africa and crypto gateways), shipping and couriers, productivity, project management, files and e-signature, analytics, databases, CMS and hosting panels (cPanel, WHM, Plesk), HR, forms, education, media, events and IoT.
- Custom apps: when a service isn't in the catalog, the AI builder (and MCP agents, via the new `create_custom_app` tool) builds it from the service's API, or you import an OpenAPI file from Apps → Add a custom app. Custom apps live in `storage/`, so updates keep them, and their requests only go to their own API address. Apps imported from a link get an "Update from OpenAPI" button.
- Workflow import and export: export one workflow or all of them as a file (credentials and passwords are left out; custom apps travel with it), and import files on any install. Steps are connected to your saved account when there is exactly one. An n8n workflow file opens the AI Builder to rebuild it.
- App, action and field descriptions written in Markdown (as OpenAPI files are) are shown formatted: bold, code, links and lists.
- Fixed: importing an OpenAPI file cut descriptions at 200 characters and removed text in angle brackets such as `<key>`.
- Fixed: an app that uses an API key as the basic-auth username with no password failed with a type error.

## [1.5.1] - 2026-10-14

- Voice agent studio: the editor is split into Persona, Conversation, Knowledge, Bookings, Capture and Voice & phone, with a check on each finished part.
- Agents now have a persona (name, personality, reply length), business knowledge, answers to common questions, bookable services and an appointment length.
- The test call is a phone screen with a voice orb that reacts to your microphone, a call timer, typing mode and mute.
- Pick the test-call voice from your browser's voices, or browse and play the Vapi voices.
- ElevenLabs agents get the whole ElevenLabs voice library: search, filter by language and gender, play samples in the chosen language, or pick from your own voices. A library voice is added to your ElevenLabs account when you pick it.
- Test calls of ElevenLabs agents speak with the agent's real ElevenLabs voice (and fall back to the browser's voice if that fails).
- Agents hang up by themselves after saying goodbye: in test calls, on Vapi (end-call tool) and on ElevenLabs (end_call system tool). Republish phone agents (Save) to get it.
- Picking a voice-library voice tests it first: on ElevenLabs' free plan library voices don't work through the API, so FlowForge says so and suggests a default voice instead of adding a voice that can't speak.
- Fixed: test calls could use macOS joke voices (Albert, Boing…); browser voices are now ranked with natural ones first.
- 12 voice agent templates (new: hotel front desk, car service booking, gym class booking, store order help), shown with sample conversations, categories and a preview. Starting from one asks for your business name.
- Fixed: an agent with no business name said "{{business_name}}" out loud; it now says "our business".

## [1.5.0] - 2026-10-14

- **Voice agents** (sidebar → Voice agents): AI agents that answer and make phone calls through Vapi or ElevenLabs.
  - FlowForge publishes the agent, its booking tool and phone number to the platform.
  - It keeps the call history: recordings, transcripts, summaries and captured details.
  - Bookings made during calls are saved and listed on their own page.
- Free browser conversations with any voice agent, using the AI model from Settings → AI builder.
- Eight voice agent templates, and "Describe it / Rewrite with AI" to write agents.
- The AI builder and MCP can create voice agents (`create_voice_agent`, `list_voice_agents`).
- Workflow triggers "When a voice call ends" and "When a caller books", an action "Call a phone number", and four templates (Google Calendar, Slack, email, call new leads).

## [1.4.0] - 2026-10-14

First public release, open source under the AGPL-3.0. Versions 1.0 to 1.3 were builds from before the public release, so the public history starts here.

- Visual workflow builder with triggers, actions, If/Else branches, filters and a step editor with drag-to-map data.
- 1,190 apps with 5,783 actions and 615 triggers, and 50 templates.
- AI Builder for OpenAI, Anthropic Claude, Google Gemini, DeepSeek and Qwen.
- Webhook, schedule, app (polling), manual and error triggers; one cron job runs everything.
- Executions with step-by-step debugging and retry.
- Team roles and permissions, email alerts, password reset by email code.
- MCP server for AI agents, with personal access tokens.
- Setup wizard with cron verification.
- One-click updates: Settings → Updates checks GitHub for a new release, shows what's new, and downloads, verifies (SHA-256) and installs it on an admin's click, with a backup and automatic rollback. Uploading the update zip by hand is still available.
- An "Update" badge on Settings when a daily check finds a newer version.
- `FLOWFORGE_UPDATE_REPO` points the updater at a fork's releases.
- Mobile-friendly layout, light and dark themes.
