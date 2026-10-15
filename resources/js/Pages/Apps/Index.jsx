import { useMemo, useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { Blocks, CheckCircle2, FileCode2, Plus, Sparkles } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import IntegrationIcon from '@/Components/IntegrationIcon';
import { plainText } from '@/Components/Markdown';
import { Button, Card, Dialog, EmptyState, Field, Input, SearchInput, Textarea } from '@/Components/ui';
import { categoryMeta } from '@/lib/categories';
import { useCan } from '@/lib/can';
import { cn } from '@/lib/utils';

/** Two ways to add an app the catalog doesn't have: the AI Builder writes it, or an OpenAPI spec is imported. */
function CustomAppDialog({ open, onClose, canAi }) {
    const [mode, setMode] = useState(canAi ? 'ai' : 'openapi');
    const [app, setApp] = useState('');
    const [goal, setGoal] = useState('');
    const form = useForm({ source: '', name: '' });
    const askAi = () => {
        const prompt = `Add ${app.trim()} as a custom app using its official API${goal.trim() ? `, so I can ${goal.trim().replace(/\.$/, '')}` : ''}. Then build the workflow with it.`;
        router.visit(`/assistant?prompt=${encodeURIComponent(prompt)}`);
    };
    return (
        <Dialog open={open} onClose={onClose} title="Add a custom app" description="For a service that isn't in the catalog. It works like any other app: credentials, actions and triggers.">
            <div className="mb-4 grid grid-cols-2 gap-2">
                {[['ai', Sparkles, 'Let AI build it', 'Name the service and what you need'], ['openapi', FileCode2, 'Import OpenAPI', 'Paste a link to its OpenAPI / Swagger spec']].map(([value, Icon, label, hint]) => (
                    (value === 'openapi' || canAi) && (
                        <button key={value} type="button" onClick={() => setMode(value)} aria-pressed={mode === value}
                            className={cn('rounded-xl border-2 px-3 py-2.5 text-left', mode === value ? 'border-primary bg-primary-soft/50' : 'border-line hover:border-line-strong')}>
                            <Icon className="mb-1 size-4 text-primary" />
                            <span className="block text-[13.5px] font-semibold">{label}</span>
                            <span className="block text-[12px] text-fg-muted">{hint}</span>
                        </button>
                    )
                ))}
            </div>
            {mode === 'ai' ? (
                <div className="space-y-3">
                    <Field label="Which app or service?">{(id) => <Input id={id} value={app} onChange={(e) => setApp(e.target.value)} placeholder="e.g. Odoo, my school's portal API, https://docs.example.com/api" autoFocus />}</Field>
                    <Field label="What should it do?" help="The AI reads the service's API, adds the app, then builds your workflow.">{(id) => <Textarea id={id} rows={3} value={goal} onChange={(e) => setGoal(e.target.value)} placeholder="create an invoice when a Shopify order is paid" />}</Field>
                    <Button variant="primary" onClick={askAi} disabled={app.trim().length < 2}><Sparkles className="size-4" />Open the AI Builder</Button>
                </div>
            ) : (
                <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); form.post('/apps/custom'); }}>
                    <Field label="OpenAPI spec link (or the JSON / YAML itself)" error={form.errors.source}>
                        {(id) => <Textarea id={id} rows={3} value={form.data.source} onChange={(e) => form.setData('source', e.target.value)} placeholder="https://api.example.com/openapi.json" className="font-mono text-[13px]" />}
                    </Field>
                    <Field label="App name" help="Optional. Taken from the spec when empty.">{(id) => <Input id={id} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />}</Field>
                    <Button type="submit" variant="primary" loading={form.processing} disabled={form.data.source.trim().length < 8}>Import app</Button>
                </form>
            )}
        </Dialog>
    );
}

function AppCard({ app, connected }) {
    return (
        <Link href={`/apps/${app.key}`} className="group flex items-start gap-3 rounded-xl border border-line bg-surface p-4 shadow-card transition-all hover:-translate-y-px hover:border-line-strong hover:shadow-pop">
            <IntegrationIcon icon={app.icon} name={app.name} size="lg" />
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1.5">
                    <p className="truncate text-[14px] font-semibold group-hover:text-primary">{app.name}</p>
                    {connected && <CheckCircle2 className="size-3.5 shrink-0 text-ok" aria-label="Credential added" />}
                </div>
                <p className="mt-0.5 line-clamp-2 text-[12.5px] leading-snug text-fg-muted">{plainText(app.description) || app.category}</p>
                <p className="mt-2 text-[11.5px] text-fg-subtle">
                    {app.actions.length} action{app.actions.length === 1 ? '' : 's'}{app.triggers.length ? `, ${app.triggers.length} trigger${app.triggers.length === 1 ? '' : 's'}` : ''}
                </p>
            </div>
        </Link>
    );
}

export default function Index({ apps, connected }) {
    const can = useCan();
    const [adding, setAdding] = useState(false);
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState(() => new URLSearchParams(window.location.search).get('category') ?? 'All');
    const connectedSet = useMemo(() => new Set(connected), [connected]);

    const categories = useMemo(() => {
        const counts = apps.reduce((acc, a) => ((acc[a.category] = (acc[a.category] ?? 0) + 1), acc), {});
        return Object.entries(counts).sort(([a], [b]) => categoryMeta(a).order - categoryMeta(b).order);
    }, [apps]);

    const q = query.trim().toLowerCase();
    const visible = apps.filter((a) => (category === 'All' || a.category === category)
        && (!q || a.name.toLowerCase().includes(q) || a.description.toLowerCase().includes(q) || a.actions.some((x) => x.name.toLowerCase().includes(q))));
    const popular = !q && category === 'All' ? apps.filter((a) => a.popular) : [];

    return (
        <AppLayout wide title="Apps" description={`${apps.length} integrations with their own credentials, actions and triggers. Every app also has a "Make an API call" action for anything not listed.`}
            actions={can['workflows.edit'] && <Button onClick={() => setAdding(true)}><Plus className="size-4" />Add a custom app</Button>}>
            <div className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[220px_minmax(0,1fr)] lg:gap-8">
                <nav aria-label="Categories" className="lg:sticky lg:top-6 lg:self-start">
                    <ul className="no-scrollbar -mx-4 flex gap-1 overflow-x-auto px-4 pb-1 lg:mx-0 lg:flex-col lg:overflow-visible lg:px-0 lg:pb-0">
                        {[['All', apps.length], ...categories].map(([name, count]) => {
                            const meta = categoryMeta(name);
                            const Icon = name === 'All' ? Blocks : meta.icon;
                            return (
                                <li key={name}>
                                    <button type="button" onClick={() => setCategory(name)} aria-pressed={category === name}
                                        className={cn('flex w-full items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-left text-[13px] whitespace-nowrap transition-colors',
                                            category === name ? 'bg-primary-soft font-medium text-primary' : 'text-fg-muted hover:bg-surface-2 hover:text-fg')}>
                                        <Icon className="size-4 shrink-0" style={category === name ? undefined : { color: meta.color }} />
                                        <span className="flex-1">{name}</span>
                                        <span className="text-[11.5px] text-fg-subtle tabular-nums">{count}</span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </nav>

                <div className="min-w-0">
                    <SearchInput value={query} onChange={(e) => setQuery(e.target.value)} placeholder={`Search ${apps.length} apps and their actions`} className="mb-6 max-w-xl" aria-label="Search apps" autoFocus />

                    {popular.length > 0 && (
                        <section className="mb-8">
                            <h2 className="mb-3 text-[15px] font-semibold">Popular</h2>
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                                {popular.slice(0, 12).map((app) => <AppCard key={app.key} app={app} connected={connectedSet.has(app.key)} />)}
                            </div>
                        </section>
                    )}

                    <h2 className="mb-3 text-[15px] font-semibold">{category === 'All' ? (q ? `Results for "${query}"` : 'All apps') : category} <span className="font-normal text-fg-subtle">{visible.length}</span></h2>
                    {visible.length === 0 ? (
                        <Card>
                            <EmptyState icon={Blocks} title="No apps match">
                                Not in the catalog? Add it as a custom app: the AI Builder can build it from the service's API.
                                {can['workflows.edit'] && <div className="mt-3"><Button size="sm" onClick={() => setAdding(true)}><Plus className="size-3.5" />Add a custom app</Button></div>}
                            </EmptyState>
                        </Card>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                            {visible.map((app) => <AppCard key={app.key} app={app} connected={connectedSet.has(app.key)} />)}
                        </div>
                    )}
                </div>
            </div>
            <CustomAppDialog open={adding} onClose={() => setAdding(false)} canAi={!!can.ai} />
        </AppLayout>
    );
}
