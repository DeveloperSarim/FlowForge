<p align="center">
  <img src=".github/readme/logo.svg" width="72" height="72" alt="FlowForge logo">
</p>

<h1 align="center">FlowForge</h1>

<p align="center">
  <strong>Open-source workflow automation that runs on PHP shared hosting.</strong><br>
  A self-hosted alternative to n8n, Zapier and Make: connect 1,190 apps on a visual canvas, describe a workflow and let AI build it, or create AI voice agents that answer your calls.<br>
  No Docker, no Node.js, no per-task fees. One cron job runs everything.
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0-ee5a24?style=flat-square" alt="License: AGPL-3.0"></a>
  <a href="https://github.com/DeveloperSarim/FlowForge/releases"><img src="https://img.shields.io/badge/version-1.4.0-15181d?style=flat-square" alt="Version 1.4.0"></a>
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777bb4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/Laravel-12-ff2d20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel 12">
  <img src="https://img.shields.io/badge/React-18-087ea4?style=flat-square&logo=react&logoColor=white" alt="React 18">
  <img src="https://img.shields.io/badge/MySQL-5.7%2B-4479a1?style=flat-square&logo=mysql&logoColor=white" alt="MySQL 5.7+">
  <a href="DEVELOPMENT.md"><img src="https://img.shields.io/badge/PRs-welcome-2ea44f?style=flat-square" alt="Pull requests welcome"></a>
</p>

<p align="center">
  <a href="https://developersarim.github.io/FlowForge/#video"><b>Watch the video</b></a> &nbsp;|&nbsp;
  <a href="https://developersarim.github.io/FlowForge/"><b>Documentation</b></a> &nbsp;|&nbsp;
  <a href="#-quick-start"><b>Quick start</b></a> &nbsp;|&nbsp;
  <a href="https://github.com/DeveloperSarim/FlowForge/releases"><b>Download</b></a> &nbsp;|&nbsp;
  <a href="#-installation-and-hosting-services"><b>Hosting &amp; services</b></a> &nbsp;|&nbsp;
  <a href="https://github.com/DeveloperSarim/FlowForge/issues"><b>Report a bug</b></a>
</p>

<p align="center">
  <a href="https://developersarim.github.io/FlowForge/#video"><img src=".github/readme/preview.gif" width="880" alt="FlowForge in action: a Typeform, HubSpot and Slack workflow runs on the canvas, then the AI Builder builds the same workflow from one sentence"></a>
  <br>
  <a href="https://developersarim.github.io/FlowForge/#video"><b>▶ Watch the full 47-second video (with sound)</b></a>
</p>

## ✨ What is FlowForge?

FlowForge is a **self-hosted workflow automation platform** for people who already have ordinary web hosting. Upload it to Hostinger, cPanel, DirectAdmin or any VPS, run the setup wizard, add one cron job, and you have your own automation server with no monthly per-task bill.

- **Connect your apps.** Gmail, Slack, Google Sheets, HubSpot, Shopify, WooCommerce, Stripe, OpenAI, Telegram, WhatsApp and 1,180 more.
- **Build visually or with AI.** Drag steps onto a canvas, or type *"When a Typeform is submitted, add the person to HubSpot and tell #sales on Slack"* and the AI Builder makes it.
- **Answer the phone with AI.** Voice agents answer and make calls, book appointments and pass every call (recording, transcript, summary) to your workflows.
- **Own your data.** Workflows, credentials and run history stay in your own MySQL database. Credentials are encrypted and never sent to the browser.

## 🧩 Features

<table>
  <tr>
    <td width="33%" valign="top">
      <img src=".github/readme/icons/workflow.svg" width="28" height="28" alt=""><br>
      <b>Visual workflow builder</b><br>
      <sub>Triggers, actions, If/Else branches and filters on a drag-and-drop canvas, with a step editor that maps data by dragging fields.</sub>
    </td>
    <td width="33%" valign="top">
      <img src=".github/readme/icons/apps.svg" width="28" height="28" alt=""><br>
      <b>1,190 apps, 5,783 actions</b><br>
      <sub>615 triggers and 50 ready-made templates across 24 categories, plus an HTTP request step for any other API.</sub>
    </td>
    <td width="33%" valign="top">
      <img src=".github/readme/icons/ai.svg" width="28" height="28" alt=""><br>
      <b>AI Builder</b><br>
      <sub>Describe a workflow in plain words and it builds, links and explains it. Works with OpenAI, Claude, Gemini, DeepSeek or Qwen, using your own key.</sub>
    </td>
  </tr>
  <tr>
    <td valign="top">
      <img src=".github/readme/icons/triggers.svg" width="28" height="28" alt=""><br>
      <b>Every kind of trigger</b><br>
      <sub>Webhooks, schedules in plain words ("every weekday at 9:00"), app triggers that watch for new items, manual runs and error triggers.</sub>
    </td>
    <td valign="top">
      <img src=".github/readme/icons/debugger.svg" width="28" height="28" alt=""><br>
      <b>Step-by-step debugger</b><br>
      <sub>Every run keeps each step's input, output and HTTP request. Retry a run or execute a single step while you build.</sub>
    </td>
    <td valign="top">
      <img src=".github/readme/icons/hosting.svg" width="28" height="28" alt=""><br>
      <b>Made for shared hosting</b><br>
      <sub>PHP and MySQL only. A setup wizard checks your server and verifies the one cron job that runs every workflow.</sub>
    </td>
  </tr>
  <tr>
    <td valign="top">
      <img src=".github/readme/icons/team.svg" width="28" height="28" alt=""><br>
      <b>Team and permissions</b><br>
      <sub>Invite admins and members, and choose who can view, edit or delete workflows and use the AI Builder.</sub>
    </td>
    <td valign="top">
      <img src=".github/readme/icons/mcp.svg" width="28" height="28" alt=""><br>
      <b>MCP server for AI agents</b><br>
      <sub>Claude Code, Cursor and VS Code agents can search apps and build, test and run workflows with a personal token.</sub>
    </td>
    <td valign="top">
      <img src=".github/readme/icons/updates.svg" width="28" height="28" alt=""><br>
      <b>One-click updates</b><br>
      <sub>Upload the update zip in Settings. FlowForge backs up, updates itself and rolls back if anything fails.</sub>
    </td>
  </tr>
  <tr>
    <td valign="top">
      <img src=".github/readme/icons/mobile.svg" width="28" height="28" alt=""><br>
      <b>Works like an app on phones</b><br>
      <sub>Bottom tab bar, bottom sheets, one-pane step editor, light and dark themes. Add it to your home screen.</sub>
    </td>
    <td valign="top">
      <img src=".github/readme/icons/security.svg" width="28" height="28" alt=""><br>
      <b>Secure by default</b><br>
      <sub>Encrypted credentials and API keys, secrets masked in run logs, email alerts when a workflow fails or cron stops.</sub>
    </td>
    <td valign="top">
      <img src=".github/readme/icons/opensource.svg" width="28" height="28" alt=""><br>
      <b>Open source</b><br>
      <sub>AGPL-3.0. Use it for yourself, your company or your clients, on as many servers as you like.</sub>
    </td>
  </tr>
</table>

## 🔌 Integrations

<p align="center">
  <img src="public/integrations/icons/gmail.svg" width="36" height="36" alt="Gmail" title="Gmail">&nbsp;
  <img src="public/integrations/icons/slack.svg" width="36" height="36" alt="Slack" title="Slack">&nbsp;
  <img src="public/integrations/icons/google_sheets.svg" width="36" height="36" alt="Google Sheets" title="Google Sheets">&nbsp;
  <img src="public/integrations/icons/google_drive.svg" width="36" height="36" alt="Google Drive" title="Google Drive">&nbsp;
  <img src="public/integrations/icons/google_calendar.svg" width="36" height="36" alt="Google Calendar" title="Google Calendar">&nbsp;
  <img src="public/integrations/icons/hubspot.svg" width="36" height="36" alt="HubSpot" title="HubSpot">&nbsp;
  <img src="public/integrations/icons/shopify.svg" width="36" height="36" alt="Shopify" title="Shopify">&nbsp;
  <img src="public/integrations/icons/paypal.svg" width="36" height="36" alt="PayPal" title="PayPal">&nbsp;
  <img src="public/integrations/icons/airtable.svg" width="36" height="36" alt="Airtable" title="Airtable">&nbsp;
  <img src="public/integrations/icons/linkedin.svg" width="36" height="36" alt="LinkedIn" title="LinkedIn">
  <br><br>
  <img src="public/integrations/icons/trello.svg" width="36" height="36" alt="Trello" title="Trello">&nbsp;
  <img src="public/integrations/icons/jira.svg" width="36" height="36" alt="Jira" title="Jira">&nbsp;
  <img src="public/integrations/icons/asana.svg" width="36" height="36" alt="Asana" title="Asana">&nbsp;
  <img src="public/integrations/icons/microsoft_teams.svg" width="36" height="36" alt="Microsoft Teams" title="Microsoft Teams">&nbsp;
  <img src="public/integrations/icons/telegram.svg" width="36" height="36" alt="Telegram" title="Telegram">&nbsp;
  <img src="public/integrations/icons/whatsapp_business.svg" width="36" height="36" alt="WhatsApp Business" title="WhatsApp Business">&nbsp;
  <img src="public/integrations/icons/discord.svg" width="36" height="36" alt="Discord" title="Discord">&nbsp;
  <img src="public/integrations/icons/zoom.svg" width="36" height="36" alt="Zoom" title="Zoom">&nbsp;
  <img src="public/integrations/icons/dropbox.svg" width="36" height="36" alt="Dropbox" title="Dropbox">&nbsp;
  <img src="public/integrations/icons/mailchimp.svg" width="36" height="36" alt="Mailchimp" title="Mailchimp">
</p>

<p align="center"><sub>…and 1,170 more across AI, CRM, e-commerce, payments, marketing, communication, project management, databases, forms, social media and developer tools.<br>Any app not on the list works through the HTTP request step or its "Make an API call" action.</sub></p>

## 📸 Screenshots

<table>
  <tr>
    <td width="50%"><img src="docs/assets/img/builder.png" alt="FlowForge workflow builder canvas with Typeform, HubSpot and Slack steps"><p align="center"><sub>Workflow builder</sub></p></td>
    <td width="50%"><img src="docs/assets/img/ai-builder.png" alt="FlowForge AI Builder chat creating a workflow from a plain-language request"><p align="center"><sub>AI Builder</sub></p></td>
  </tr>
  <tr>
    <td><img src="docs/assets/img/step-editor.png" alt="FlowForge step editor with input, parameters and output panes"><p align="center"><sub>Step editor with drag-to-map data</sub></p></td>
    <td><img src="docs/assets/img/executions.png" alt="FlowForge executions list with a run's step-by-step details"><p align="center"><sub>Executions and step debugger</sub></p></td>
  </tr>
</table>

<details>
<summary><b>On a phone</b></summary>
<br>
<p align="center">
  <img src="docs/assets/img/mobile-overview.png" width="200" alt="FlowForge overview on a phone">&nbsp;
  <img src="docs/assets/img/mobile-workflows.png" width="200" alt="FlowForge workflows on a phone">&nbsp;
  <img src="docs/assets/img/mobile-step-editor.png" width="200" alt="FlowForge step editor on a phone">&nbsp;
  <img src="docs/assets/img/mobile-ai.png" width="200" alt="FlowForge AI Builder on a phone">
</p>
</details>

## 🚀 Quick start

You need hosting with **PHP 8.2+, MySQL and cron** (every Hostinger and cPanel plan has these). No SSH, Composer or Node.js on the server.

1. **Download** `flowforge-install-x.y.z.zip` from the [latest release](https://github.com/DeveloperSarim/FlowForge/releases).
2. **Create an empty MySQL database** and a user in your hosting panel.
3. **Upload and extract** the zip into your domain's folder (for example `public_html`). If your File Manager times out, also upload `extract-flowforge.php` and open it in your browser.
4. **Open** `https://your-domain.com/install`. The wizard checks your server, connects the database, creates your admin account and sets up email alerts.
5. **Add the cron job** the wizard shows you (or click *Set it up for me*). It turns green within a minute, and you're done.

Step-by-step guides with screenshots: [Hostinger hPanel](https://developersarim.github.io/FlowForge/#hpanel) | [cPanel](https://developersarim.github.io/FlowForge/#cpanel) | [VPS with Nginx](https://developersarim.github.io/FlowForge/#vps) | [Cron job](https://developersarim.github.io/FlowForge/#cron)

<details>
<summary><b>Server requirements</b></summary>
<br>

| Requirement | Details |
| --- | --- |
| PHP | 8.2 or newer, with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `fileinfo`, `gd`, `zip` |
| Database | MySQL 5.7+ or MariaDB 10.3+ |
| Web server | Apache or LiteSpeed with `mod_rewrite`, or Nginx |
| Cron | One job every minute |
| Disk | About 150 MB plus run history |

</details>

## 🆚 How FlowForge compares

| | **FlowForge** | n8n | Zapier | Make |
| --- | :---: | :---: | :---: | :---: |
| Self-hosted on your own server | ✅ | ✅ | ❌ | ❌ |
| Runs on cPanel / hPanel shared hosting | ✅ | ❌ needs a Node.js server | ❌ cloud only | ❌ cloud only |
| OSI-approved open-source license | ✅ AGPL-3.0 | ❌ fair-code | ❌ | ❌ |
| No per-task or per-operation fees | ✅ | ✅ when self-hosted | ❌ | ❌ |

<sub>Based on each product's public information as of October 2026.</sub>

## 📞 Voice agents

Build AI agents that **answer and make phone calls**: a receptionist that books appointments, a caller that qualifies new leads, a reminder line.

- **Start in seconds:** 8 templates (clinic, salon, restaurant, lead qualification, reminders, support, real estate, feedback), or describe the agent and AI writes it.
- **Try it free:** talk to any agent in your browser, using your own AI model. No phone number needed.
- **Real calls** through [Vapi](https://vapi.ai) or [ElevenLabs](https://elevenlabs.io). Both have free tiers to start, and FlowForge sets up the agent, booking tool and number for you.
- **Everything in one place:** call history with recordings, transcripts, summaries and captured details, plus a Bookings page.
- **Workflows:** "When a voice call ends", "When a caller books" and "Call a phone number". Send bookings to Google Calendar, summaries to Slack, or call every new lead automatically.

## 🤖 AI Builder and MCP

**AI Builder.** Open *AI Builder* in the sidebar and describe what you want. It searches the app catalog, reads each action's settings, saves the workflow, links the data between steps and tells you what's left to fill in. Bring your own key from OpenAI, Anthropic Claude, Google Gemini, DeepSeek or Qwen.

**MCP for AI agents.** Create a token in *Settings → API & MCP*, then connect your agent. For example, Claude Code:

```bash
claude mcp add --transport http flowforge https://your-domain.com/api/mcp \
  --header "Authorization: Bearer YOUR_TOKEN"
```

## 📚 Documentation

The full guide is at **[developersarim.github.io/FlowForge](https://developersarim.github.io/FlowForge/)**:

- [Installation](https://developersarim.github.io/FlowForge/#before-you-start) on Hostinger hPanel, cPanel and VPS, and the [setup wizard](https://developersarim.github.io/FlowForge/#setup-wizard)
- [Your first workflow](https://developersarim.github.io/FlowForge/#first-workflow), [triggers](https://developersarim.github.io/FlowForge/#triggers) and [passing data between steps](https://developersarim.github.io/FlowForge/#data-mapping)
- [Credentials and app connections](https://developersarim.github.io/FlowForge/#credentials), [AI Builder](https://developersarim.github.io/FlowForge/#ai-builder) and [team permissions](https://developersarim.github.io/FlowForge/#team)
- [Updating](https://developersarim.github.io/FlowForge/#updating), [backups](https://developersarim.github.io/FlowForge/#backups) and [troubleshooting](https://developersarim.github.io/FlowForge/#troubleshooting)

Developers: architecture, the integration manifest format, local setup and releases are in **[DEVELOPMENT.md](DEVELOPMENT.md)**.

## 💼 Installation and hosting services

FlowForge is free. If you'd rather not set it up or run it yourself, we can help:

| | Service |
| :---: | --- |
| 🛠️ | **Installation** on your Hostinger hPanel, cPanel or VPS, including the database, SSL and cron job |
| ☁️ | **Managed hosting**: we run FlowForge for you, with backups and updates |
| 🧭 | **Setup help**: connecting your apps and building your first workflows |
| 🔧 | **Custom integrations** for apps or APIs that aren't in the catalog yet |

Email **[support@sarimtools.com](mailto:support@sarimtools.com?subject=FlowForge%20services)** with what you need and we'll send you a quote.

## ❓ FAQ

<details>
<summary><b>Is FlowForge really free?</b></summary>
<br>
Yes. It's open source under the AGPL-3.0 license, with no limits on users, workflows, runs or sites. You only pay if you want our installation or hosting services.
</details>

<details>
<summary><b>Do I need a cron job for every workflow?</b></summary>
<br>
No. One cron job, running every minute, runs every schedule, webhook and app trigger.
</details>

<details>
<summary><b>Can I use it for my business or my clients?</b></summary>
<br>
Yes. If you change FlowForge's code and offer the changed version to other people over a network, the AGPL requires you to offer them your source code too.
</details>

<details>
<summary><b>How is it different from n8n?</b></summary>
<br>
n8n needs a Node.js server, usually a VPS or Docker. FlowForge is PHP, so it runs on the shared hosting most websites already use, and it's licensed under an OSI-approved open-source license.
</details>

<details>
<summary><b>How fast do webhooks run?</b></summary>
<br>
Within about a minute, on the next cron tick. Test runs in the editor run straight away.
</details>

## 🤝 Contributing

Bug reports, ideas and pull requests are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) and the [Code of Conduct](CODE_OF_CONDUCT.md).

- Found a bug? [Open an issue](https://github.com/DeveloperSarim/FlowForge/issues) with your FlowForge and PHP versions and the steps to reproduce it.
- Want to add an app? Integrations are JSON/YAML manifests, not PHP classes. See [DEVELOPMENT.md](DEVELOPMENT.md).

```bash
git clone https://github.com/DeveloperSarim/FlowForge.git && cd FlowForge
composer install && npm install && npm run build
cd public && php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
# open http://127.0.0.1:8000/install
```

## 🔒 Security

Please report security issues privately to [support@sarimtools.com](mailto:support@sarimtools.com?subject=FlowForge%20security) rather than in a public issue.

## 📄 License

FlowForge is free software under the [GNU Affero General Public License v3.0](LICENSE). App names and logos are trademarks of their owners, used only to identify each integration.

<p align="center"><sub>Built with Laravel, React, React Flow and Tailwind CSS.</sub></p>
