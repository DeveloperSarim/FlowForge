# Changelog

All notable changes to FlowForge. Versions follow [semantic versioning](https://semver.org).

## [Unreleased]

- **Voice agents** (sidebar → Voice agents): AI agents that answer and make phone calls through Vapi or ElevenLabs.
  - FlowForge publishes the agent, its booking tool and phone number to the platform.
  - It keeps the call history: recordings, transcripts, summaries and captured details.
  - Bookings made during calls are saved and listed on their own page.
- Free browser conversations with any voice agent, using the AI model from Settings → AI builder.
- Eight voice agent templates, and "Describe it / Rewrite with AI" to write agents.
- The AI builder and MCP can create voice agents (`create_voice_agent`, `list_voice_agents`).
- Workflow triggers "When a voice call ends" and "When a caller books", an action "Call a phone number", and four templates (Google Calendar, Slack, email, call new leads).

## [1.4.0] - 2026-10-14

The version jumps from 1.0.0 to 1.4.0 so installs from before the public release (1.3.x) can update in place.


- One-click updates: Settings → Updates checks GitHub for a new release, shows what's new, and downloads, verifies (SHA-256) and installs it on an admin's click. Uploading the update zip by hand is still available.
- An "Update" badge on Settings when a daily check finds a newer version.
- `FLOWFORGE_UPDATE_REPO` points the updater at a fork's releases.

## [1.0.0] - 2026-10-12

First public release, open source under the AGPL-3.0.

- Visual workflow builder with triggers, actions, If/Else branches, filters and a step editor with drag-to-map data.
- 1,190 apps with 5,783 actions and 615 triggers, and 50 templates.
- AI Builder for OpenAI, Anthropic Claude, Google Gemini, DeepSeek and Qwen.
- Webhook, schedule, app (polling), manual and error triggers; one cron job runs everything.
- Executions with step-by-step debugging and retry.
- Team roles and permissions, email alerts, password reset by email code.
- MCP server for AI agents, with personal access tokens.
- Setup wizard with cron verification; one-click updates with backup and rollback.
- Public demo mode (`FLOWFORGE_DEMO=true`).
- Mobile-friendly layout, light and dark themes.

[1.4.0]: https://github.com/DeveloperSarim/FlowForge/releases/tag/v1.4.0
[1.0.0]: https://github.com/DeveloperSarim/FlowForge/releases/tag/v1.0.0
