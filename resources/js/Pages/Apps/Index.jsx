import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Blocks, CheckCircle2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import IntegrationIcon from '@/Components/IntegrationIcon';
import { Card, EmptyState, SearchInput } from '@/Components/ui';
import { categoryMeta } from '@/lib/categories';
import { cn } from '@/lib/utils';

function AppCard({ app, connected }) {
    return (
        <Link href={`/apps/${app.key}`} className="group flex items-start gap-3 rounded-xl border border-line bg-surface p-4 shadow-card transition-all hover:-translate-y-px hover:border-line-strong hover:shadow-pop">
            <IntegrationIcon icon={app.icon} name={app.name} size="lg" />
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1.5">
                    <p className="truncate text-[14px] font-semibold group-hover:text-primary">{app.name}</p>
                    {connected && <CheckCircle2 className="size-3.5 shrink-0 text-ok" aria-label="Credential added" />}
                </div>
                <p className="mt-0.5 line-clamp-2 text-[12.5px] leading-snug text-fg-muted">{app.description || app.category}</p>
                <p className="mt-2 text-[11.5px] text-fg-subtle">
                    {app.actions.length} action{app.actions.length === 1 ? '' : 's'}{app.triggers.length ? `, ${app.triggers.length} trigger${app.triggers.length === 1 ? '' : 's'}` : ''}
                </p>
            </div>
        </Link>
    );
}

export default function Index({ apps, connected }) {
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
        <AppLayout wide title="Apps" description={`${apps.length} integrations with their own credentials, actions and triggers. Every app also has a "Make an API call" action for anything not listed.`}>
            <div className="grid gap-8 lg:grid-cols-[220px_1fr]">
                <nav aria-label="Categories" className="lg:sticky lg:top-6 lg:self-start">
                    <ul className="flex gap-1 overflow-x-auto pb-2 lg:flex-col lg:overflow-visible lg:pb-0">
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
                        <Card><EmptyState icon={Blocks} title="No apps match">Use the HTTP Request node for any API, or drop a manifest / OpenAPI file into resources/integrations.</EmptyState></Card>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                            {visible.map((app) => <AppCard key={app.key} app={app} connected={connectedSet.has(app.key)} />)}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
