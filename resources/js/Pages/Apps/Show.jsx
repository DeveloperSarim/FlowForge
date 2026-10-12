import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, BookOpen, ExternalLink, KeyRound, Play, Zap } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import IntegrationIcon from '@/Components/IntegrationIcon';
import TemplateCard from '@/Components/TemplateCard';
import { Badge, Button, Card } from '@/Components/ui';
import { categoryMeta } from '@/lib/categories';
import { useCan } from '@/lib/can';

const AUTH_LABELS = { api_key: 'API key', bearer: 'Access token', basic: 'Username & password', oauth2: 'OAuth2', google_service_account: 'Service account', smtp: 'SMTP server', mysql: 'MySQL server' };

export default function Show({ app, credentials, templates }) {
    const [creating, setCreating] = useState(null);
    const can = useCan();
    const meta = categoryMeta(app.category);

    const startWith = (action) => {
        setCreating(action.key);
        router.post('/workflows', { name: `${app.name}: ${action.name}`, integration: app.key, action: action.key }, { onFinish: () => setCreating(null) });
    };

    return (
        <AppLayout title={null}>
            <Link href="/apps" className="mb-5 inline-flex items-center gap-1.5 text-[13px] font-medium text-fg-muted hover:text-fg"><ArrowLeft className="size-4" />All apps</Link>

            <Card className="flex flex-wrap items-start gap-5 p-6">
                <IntegrationIcon icon={app.icon} name={app.name} size="xl" />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-[24px] font-semibold tracking-tight">{app.name}</h1>
                        <Link href={`/apps?category=${encodeURIComponent(app.category)}`}>
                            <Badge className="gap-1.5"><meta.icon className="size-3" style={{ color: meta.color }} />{app.category}</Badge>
                        </Link>
                    </div>
                    <p className="mt-1 max-w-2xl text-[14px] text-fg-muted">{app.description}</p>
                    <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-fg-muted">
                        {app.auth.length > 0 && <span className="inline-flex items-center gap-1.5"><KeyRound className="size-3.5" />{app.auth.map((a) => AUTH_LABELS[a] ?? a).join(' or ')}</span>}
                        {app.website && <a href={app.website} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 hover:text-primary">Website<ExternalLink className="size-3" /></a>}
                        {app.docs_url && <a href={app.docs_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 hover:text-primary"><BookOpen className="size-3.5" />API docs</a>}
                        {app.base_url && <code className="font-mono text-[12px] text-fg-subtle">{app.base_url}</code>}
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    {can['workflows.edit'] && app.auth.length > 0 && <Button onClick={() => router.visit(`/credentials?new=${app.key}`)}><KeyRound className="size-4" />{credentials.length ? 'Add another credential' : 'Add credential'}</Button>}
                    {can['workflows.edit'] && app.actions[0] && <Button variant="primary" loading={creating === app.actions[0].key} onClick={() => startWith(app.actions[0])}><Play className="size-3.5" />Use in a workflow</Button>}
                </div>
            </Card>

            <div className="mt-6 grid gap-6 lg:grid-cols-[1.4fr_1fr]">
                <Card>
                    <h2 className="px-5 pt-5 pb-3 text-[15px] font-semibold">Actions <span className="font-normal text-fg-subtle">{app.actions.length}</span></h2>
                    <ul className="divide-y divide-line border-t border-line">
                        {app.actions.map((action) => (
                            <li key={action.key} className="group flex items-center gap-4 px-5 py-3">
                                <div className="min-w-0 flex-1">
                                    <p className="text-[13.5px] font-medium">{action.name}</p>
                                    {action.description && <p className="mt-0.5 text-[12.5px] text-fg-muted">{action.description}</p>}
                                </div>
                                {can['workflows.edit'] && <Button size="sm" variant="ghost" className="opacity-0 group-hover:opacity-100 focus:opacity-100 pointer-coarse:opacity-100" loading={creating === action.key} onClick={() => startWith(action)}>Use</Button>}
                            </li>
                        ))}
                    </ul>
                </Card>
                <div className="space-y-6">
                    <Card>
                        <h2 className="px-5 pt-5 pb-3 text-[15px] font-semibold">Triggers <span className="font-normal text-fg-subtle">{app.triggers.length}</span></h2>
                        {app.triggers.length === 0 ? (
                            <p className="border-t border-line px-5 py-4 text-[13px] text-fg-muted">No app-specific triggers. Start workflows with a Webhook, Schedule or Manual trigger and use {app.name} as a step.</p>
                        ) : (
                            <ul className="divide-y divide-line border-t border-line">
                                {app.triggers.map((t) => (
                                    <li key={t.key} className="flex items-start gap-3 px-5 py-3">
                                        <Zap className="mt-0.5 size-4 shrink-0 text-warn" />
                                        <div>
                                            <p className="text-[13.5px] font-medium">{t.name}</p>
                                            <p className="text-[12.5px] text-fg-muted">{t.type === 'polling' ? 'Checks for new items on a schedule' : t.type === 'webhook' ? 'Instant, via a webhook URL you paste into the app' : t.description}</p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                    <Card>
                        <h2 className="px-5 pt-5 pb-3 text-[15px] font-semibold">Your credentials</h2>
                        {credentials.length === 0 ? (
                            <p className="border-t border-line px-5 py-4 text-[13px] text-fg-muted">{app.auth.length ? 'None yet. Add one to use this app in workflows.' : 'This app needs no credential.'}</p>
                        ) : (
                            <ul className="divide-y divide-line border-t border-line">
                                {credentials.map((c) => <li key={c.id} className="flex items-center justify-between px-5 py-2.5 text-[13.5px]"><span className="font-medium">{c.name}</span><span className="text-[12px] text-fg-subtle">{AUTH_LABELS[c.auth_type] ?? c.auth_type}</span></li>)}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>

            {templates.length > 0 && (
                <section className="mt-8">
                    <h2 className="mb-3 text-[15px] font-semibold">Templates with {app.name}</h2>
                    <div className="grid gap-4 md:grid-cols-3">{templates.map((t) => <TemplateCard key={t.key} template={t} />)}</div>
                </section>
            )}
        </AppLayout>
    );
}
