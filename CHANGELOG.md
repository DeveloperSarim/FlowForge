# Changelog

All notable changes to FlowForge. Versions follow [semantic versioning](https://semver.org).

## [Unreleased]

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
