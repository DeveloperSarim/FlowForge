<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\Controllers\McpController;
use App\Domain\Assistant\WorkflowTools;
use App\Domain\Integrations\ManifestLoader;
use Illuminate\Http\Response;

/**
 * Public, crawler- and agent-facing files. They are routes (not files in public/) so every install
 * advertises its own domain. Only the sign-in page is indexable; the app itself is private.
 */
class SeoController extends Controller
{
    private const PRIVATE_PATHS = ['/install', '/invite/', '/api/', '/oauth/', '/forgot-password', '/reset-password', '/settings',
        '/workflows', '/executions', '/credentials', '/assistant', '/templates', '/apps', '/integrations/', '/schedule/'];

    public function robots(): Response
    {
        $lines = ['User-agent: *', 'Allow: /login', 'Allow: /llms.txt', 'Allow: /llms-full.txt',
            ...array_map(fn ($path) => "Disallow: {$path}", self::PRIVATE_PATHS), '', 'Sitemap: '.url('/sitemap.xml')];

        return $this->text(implode("\n", $lines)."\n");
    }

    public function sitemap(): Response
    {
        $urls = collect([['/login', '1.0'], ['/llms.txt', '0.5'], ['/llms-full.txt', '0.5']])
            ->map(fn ($u) => '  <url><loc>'.e(url($u[0])).'</loc><changefreq>weekly</changefreq><priority>'.$u[1].'</priority></url>')
            ->implode("\n");

        return response("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n{$urls}\n</urlset>\n", 200, [
            'Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /** https://llmstxt.org: what this is and how an agent connects. */
    public function llms(ManifestLoader $manifests, WorkflowTools $tools): Response
    {
        $base = url('/');
        [$apps, $actions, $triggers] = $this->counts($manifests);
        $toolLines = collect($tools->definitions())->map(fn ($t) => "- `{$t['name']}`: {$t['description']}")->implode("\n");

        return $this->text(<<<MD
# FlowForge

> FlowForge is self-hosted workflow automation (like n8n or Zapier) that runs on ordinary PHP shared hosting with one cron job. It connects {$apps} apps ({$actions} actions, {$triggers} triggers) on a visual canvas, has an AI workflow builder, and an MCP server so AI agents can build and run workflows.

This is the FlowForge instance at {$base}. Its workflows, credentials and runs are private to its team; the endpoints below are for people and agents with an account.

## For AI agents

- [MCP server]({$base}/api/mcp): Streamable HTTP, JSON-RPC 2.0 over POST. Send `Authorization: Bearer <token>`; a signed-in user creates the token under Settings → API & MCP, and the agent gets that user's permissions.
- [Full reference]({$base}/llms-full.txt): how workflows are built (triggers, steps, `{{ steps.<id>.<field> }}` data mapping, config formats), every MCP tool with its parameters, and the complete app catalog.

## MCP tools

{$toolLines}

## Connect from Claude Code

```
claude mcp add --transport http flowforge {$base}/api/mcp --header "Authorization: Bearer <token>"
```

## Optional

- [Sign in]({$base}/login)
- [Sitemap]({$base}/sitemap.xml)

MD);
    }

    /** Everything an agent needs in one file: connection, the building guide, tool schemas and every app. */
    public function llmsFull(ManifestLoader $manifests, WorkflowTools $tools): Response
    {
        $base = url('/');
        [$apps, $actions, $triggers] = $this->counts($manifests);
        $out = ["# FlowForge: full reference for AI agents\n",
            "> Self-hosted workflow automation at {$base}: {$apps} apps, {$actions} actions, {$triggers} triggers, an AI workflow builder and an MCP server.\n",
            "## Connecting over MCP\n",
            "- Endpoint: `{$base}/api/mcp` (Streamable HTTP; JSON-RPC 2.0 over POST; protocol versions 2025-06-18, 2025-03-26, 2024-11-05).",
            '- Auth: `Authorization: Bearer <token>`. A signed-in user creates tokens under Settings → API & MCP; the agent acts with that user\'s permissions (workflows view / edit / delete, AI builder).',
            "- Claude Code: `claude mcp add --transport http flowforge {$base}/api/mcp --header \"Authorization: Bearer <token>\"`",
            '- Cursor (`~/.cursor/mcp.json`): `{"mcpServers": {"flowforge": {"url": "'.$base.'/api/mcp", "headers": {"Authorization": "Bearer <token>"}}}}`'."\n",
            "## How to build workflows\n",
            McpController::instructions(),
            "## Tools\n",
        ];
        foreach ($tools->definitions() as $tool) {
            $out[] = "### {$tool['name']}\n\n{$tool['description']}\n\nParameters (JSON Schema): `".json_encode($tool['parameters'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."`\n";
        }

        $out[] = "## App catalog\n\nUse the key with `get_app` for exact inputs and outputs. Every app with an API also has an `api_call` action.\n";
        foreach (collect($manifests->catalog())->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->groupBy('category')->sortKeys() as $category => $group) {
            $out[] = "### {$category} (".count($group).")\n";
            foreach ($group as $app) {
                $line = "- **{$app['name']}** (`{$app['key']}`)".($app['description'] ? ": {$app['description']}" : '');
                $acts = collect($app['actions'])->reject(fn ($a) => $a['key'] === 'api_call')->map(fn ($a) => "`{$a['key']}` {$a['name']}")->implode(', ');
                $trigs = collect($app['triggers'])->map(fn ($t) => "`{$t['key']}` {$t['name']}")->implode(', ');
                $out[] = $line.($acts ? "\n  - Actions: {$acts}" : '').($trigs ? "\n  - Triggers: {$trigs}" : '');
            }
            $out[] = '';
        }

        return $this->text(implode("\n", $out));
    }

    private function counts(ManifestLoader $manifests): array
    {
        $catalog = $manifests->catalog();

        return array_map('number_format', [count($catalog), array_sum(array_map(fn ($a) => count($a['actions']), $catalog)), array_sum(array_map(fn ($a) => count($a['triggers']), $catalog))]);
    }

    private function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
