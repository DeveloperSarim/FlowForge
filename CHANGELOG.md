# Changelog

All notable changes to FlowForge. Versions follow [semantic versioning](https://semver.org).

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
