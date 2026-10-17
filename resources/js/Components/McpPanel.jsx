import { useState } from 'react';
import { Copy, KeyRound, Plus, TriangleAlert } from 'lucide-react';
import { Button, Card, ConfirmButton, Field, Input, Tabs } from '@/Components/ui';
import { copy, errorMessage, timeAgo, toast } from '@/lib/utils';

/** Ready-to-paste setup for the common MCP clients. */
function snippets(url, token) {
    const auth = `Bearer ${token}`;
    return {
        claude: { label: 'Claude Code', hint: 'Run in your terminal:', code: `claude mcp add --transport http flowforge ${url} --header "Authorization: ${auth}"` },
        cursor: { label: 'Cursor', hint: 'Add to ~/.cursor/mcp.json:', code: JSON.stringify({ mcpServers: { flowforge: { url, headers: { Authorization: auth } } } }, null, 2) },
        vscode: { label: 'VS Code', hint: 'Add to .vscode/mcp.json:', code: JSON.stringify({ servers: { flowforge: { type: 'http', url, headers: { Authorization: auth } } } }, null, 2) },
        other: { label: 'Other agents', hint: 'Any MCP client with Streamable HTTP and custom headers:', code: `URL:     ${url}\nHeader:  Authorization: ${auth}` },
    };
}

function Code({ children }) {
    return (
        <div className="flex items-start gap-2 rounded-lg border border-line bg-surface-2 p-2">
            <pre className="min-w-0 flex-1 overflow-x-auto p-1 font-mono text-12 leading-relaxed whitespace-pre">{children}</pre>
            <Button size="xs" variant="outline" className="shrink-0" onClick={() => copy(children, 'Copied')}><Copy className="size-3.5" />Copy</Button>
        </div>
    );
}

/** Settings → API & MCP: personal tokens so AI agents can build workflows with your access. */
export default function McpPanel({ mcp }) {
    const [tokens, setTokens] = useState(mcp.tokens);
    const [name, setName] = useState('Claude Code');
    const [created, setCreated] = useState(null);
    const [client, setClient] = useState('claude');
    const [busy, setBusy] = useState(false);

    const create = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            const { data } = await window.axios.post('/settings/tokens', { name });
            setTokens(data.tokens);
            setCreated(data.token);
        } catch (error) {
            toast(errorMessage(error), 'error');
        } finally {
            setBusy(false);
        }
    };

    const revoke = async (id) => {
        try {
            const { data } = await window.axios.delete(`/settings/tokens/${id}`);
            setTokens(data.tokens);
            toast('Token revoked. Agents using it are disconnected.', 'success');
        } catch (error) {
            toast(errorMessage(error), 'error');
        }
    };

    const setups = snippets(mcp.url, created ?? 'YOUR_TOKEN');

    return (
        <div className="space-y-6">
            <Card className="p-6">
                <h2 className="text-16 font-semibold">Connect AI agents (MCP)</h2>
                <p className="mt-0.5 max-w-2xl text-13 text-fg-muted">Claude Code, Cursor, VS Code and other MCP agents can search apps, build, edit, test and run workflows here. They act as you, with exactly your access.</p>
                <Field label="MCP endpoint" className="mt-4">
                    <div className="flex items-center gap-2 rounded-lg border border-line bg-surface-2 p-2">
                        <code className="min-w-0 flex-1 truncate font-mono text-12h">{mcp.url}</code>
                        <Button size="xs" variant="ghost" onClick={() => copy(mcp.url, 'Endpoint copied')}><Copy className="size-3.5" /></Button>
                    </div>
                </Field>

                {created ? (
                    <div className="animate-pop-in mt-5 rounded-xl border border-ok/40 bg-ok-soft/40 p-4">
                        <p className="flex items-center gap-2 text-13h font-medium"><TriangleAlert className="size-4 text-warn" />Copy your token now: it won't be shown again.</p>
                        <Code>{created}</Code>
                    </div>
                ) : (
                    <form onSubmit={create} className="mt-5 flex flex-wrap items-end gap-2">
                        <Field label="New token" help="Name it after the agent or computer that uses it." className="min-w-56 flex-1">
                            {(id) => <Input id={id} value={name} onChange={(e) => setName(e.target.value)} required maxLength={100} />}
                        </Field>
                        <Button type="submit" variant="primary" loading={busy} className="mb-[22px]"><Plus className="size-4" />Create token</Button>
                    </form>
                )}

                <div className="mt-6">
                    <Tabs value={client} onChange={setClient} items={Object.entries(setups).map(([value, s]) => ({ value, label: s.label }))} className="mb-3 flex-wrap" />
                    <p className="mb-1.5 text-12h text-fg-muted">{setups[client].hint}</p>
                    <Code>{setups[client].code}</Code>
                    {!created && <p className="mt-1.5 text-12 text-fg-subtle">Create a token first; it is filled in here for you.</p>}
                </div>
            </Card>

            <Card className="p-6">
                <h3 className="text-14 font-semibold">Your tokens</h3>
                {tokens.length === 0 ? (
                    <p className="mt-2 text-13 text-fg-muted">No tokens yet.</p>
                ) : (
                    <ul className="mt-3 divide-y divide-line border-t border-line">
                        {tokens.map((t) => (
                            <li key={t.id} className="flex items-center gap-3 py-2.5">
                                <KeyRound className="size-4 shrink-0 text-fg-subtle" />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-13h font-medium">{t.name}</p>
                                    <p className="text-12 text-fg-subtle">Created {timeAgo(t.created_at)}, {t.last_used_at ? `last used ${timeAgo(t.last_used_at)}` : 'never used'}</p>
                                </div>
                                <ConfirmButton onConfirm={() => revoke(t.id)} confirmLabel="Revoke?">Revoke</ConfirmButton>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}
