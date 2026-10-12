import { Link, router, usePage } from '@inertiajs/react';
import { ArrowDownRight, ArrowRight, ArrowUpRight, Blocks, Plus, Workflow as WorkflowIcon } from 'lucide-react';
import AiIcon from '@/Components/AiIcon';
import AppLayout, { openCreateWorkflow } from '@/Layouts/AppLayout';
import ExecutionsChart from '@/Components/ExecutionsChart';
import IntegrationIcon, { AppStack } from '@/Components/IntegrationIcon';
import TemplateCard from '@/Components/TemplateCard';
import { Button, Card, EmptyState, StatusBadge } from '@/Components/ui';
import { cn, compact, duration, timeAgo } from '@/lib/utils';
import { useCan } from '@/lib/can';

function Delta({ current, previous, upIsGood = true, format = (v) => v }) {
    if (!previous && !current) return <span className="text-[12px] text-fg-subtle">No runs in either week</span>;
    if (!previous) return <span className="text-[12px] text-fg-subtle">vs none the week before</span>;
    const change = ((current - previous) / previous) * 100;
    if (Math.abs(change) < 0.5) return <span className="text-[12px] text-fg-subtle">Same as last week</span>;
    const up = change > 0;
    const good = up === upIsGood;
    const Arrow = up ? ArrowUpRight : ArrowDownRight;
    return (
        <span className="flex items-center gap-1 text-[12px]">
            <span className={cn('inline-flex items-center gap-0.5 rounded-md px-1 font-medium', good ? 'bg-ok-soft text-ok' : 'bg-fail-soft text-fail')}>
                <Arrow className="size-3" />{Math.abs(change).toFixed(change > -10 && change < 10 ? 1 : 0)}%
            </span>
            <span className="text-fg-subtle">vs {format(previous)} last week</span>
        </span>
    );
}

function Stat({ label, value, children }) {
    return (
        <div className="px-5 py-4">
            <p className="text-[13px] text-fg-muted">{label}</p>
            <p className="mt-1 text-[26px] font-semibold tracking-tight tabular-nums">{value}</p>
            <div className="mt-1 min-h-5">{children}</div>
        </div>
    );
}

export default function Overview({ metrics, series, recent, workflows, templates }) {
    const can = useCan();
    const { auth } = usePage().props;
    const { current, previous } = metrics;
    const hour = new Date().getHours();
    const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';

    return (
        <AppLayout
            title={`${greeting}, ${auth.user?.name?.split(' ')[0] ?? 'there'}`}
            description="Here's how your automations ran over the last 7 days. Editor test runs aren't counted."
            actions={<>
                <Button onClick={() => router.visit('/templates')}>Browse templates</Button>
                {can.ai && <Button onClick={() => router.visit('/assistant')}><AiIcon className="size-4 text-primary" />Build with AI</Button>}
                {can['workflows.edit'] && <Button variant="primary" onClick={openCreateWorkflow}><Plus className="size-4" />Create workflow</Button>}
            </>}
        >
            <Card className="grid grid-cols-2 divide-line max-lg:[&>*:nth-child(n+3)]:border-t sm:grid-cols-3 lg:grid-cols-5 lg:divide-x">
                <Stat label="Production runs" value={compact(current.total)}><Delta current={current.total} previous={previous.total} /></Stat>
                <Stat label="Failed runs" value={compact(current.failed)}><Delta current={current.failed} previous={previous.failed} upIsGood={false} /></Stat>
                <Stat label="Failure rate" value={`${current.failure_rate}%`}><Delta current={current.failure_rate} previous={previous.failure_rate} upIsGood={false} format={(v) => `${v}%`} /></Stat>
                <Stat label="Avg. run time" value={duration(current.avg_ms)}><Delta current={current.avg_ms} previous={previous.avg_ms} upIsGood={false} format={duration} /></Stat>
                <Stat label="Active workflows" value={metrics.active_workflows}>
                    <span className="text-[12px] text-fg-subtle">of {metrics.workflows} workflow{metrics.workflows === 1 ? '' : 's'}</span>
                </Stat>
            </Card>

            <div className="mt-6 grid gap-6 xl:grid-cols-[1.6fr_1fr]">
                <Card className="min-w-0 p-5">
                    <h2 className="mb-1 text-[15px] font-semibold">Runs per day</h2>
                    <p className="mb-4 text-[13px] text-fg-muted">Last 14 days, production runs only.</p>
                    <ExecutionsChart series={series} />
                </Card>

                <Card className="flex min-w-0 flex-col">
                    <div className="flex items-center justify-between px-5 pt-5 pb-3">
                        <h2 className="text-[15px] font-semibold">Recent runs</h2>
                        <Link href="/executions" className="flex items-center gap-1 text-[13px] font-medium text-fg-muted hover:text-primary">View all<ArrowRight className="size-3.5" /></Link>
                    </div>
                    {recent.length === 0 ? (
                        <p className="px-5 pb-6 text-[13px] text-fg-subtle">Runs show up here once a workflow is triggered or tested.</p>
                    ) : (
                        <ul className="divide-y divide-line border-t border-line">
                            {recent.map((run) => (
                                <li key={run.id}>
                                    <Link href={`/executions?open=${run.id}`} className="flex items-center gap-3 px-5 py-2.5 hover:bg-surface-2">
                                        <StatusBadge status={run.status} />
                                        <span className="min-w-0 flex-1 truncate text-[13px] font-medium">{run.workflow?.name ?? 'Deleted workflow'}</span>
                                        <span className="hidden text-[12px] text-fg-subtle capitalize 2xl:inline">{run.mode}</span>
                                        <span className="shrink-0 text-right text-[12px] whitespace-nowrap text-fg-subtle">{timeAgo(run.created_at)}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            <div className="mt-6 grid gap-6 xl:grid-cols-[1.6fr_1fr]">
                <Card className="min-w-0">
                    <div className="flex items-center justify-between px-5 pt-5 pb-3">
                        <h2 className="text-[15px] font-semibold">Recently edited</h2>
                        <Link href="/workflows" className="flex items-center gap-1 text-[13px] font-medium text-fg-muted hover:text-primary">All workflows<ArrowRight className="size-3.5" /></Link>
                    </div>
                    {workflows.length === 0 ? (
                        <EmptyState icon={WorkflowIcon} title="No workflows yet" action={can['workflows.edit'] && <Button variant="primary" onClick={openCreateWorkflow}><Plus className="size-4" />Create workflow</Button>}>
                            Start from scratch or pick a template.
                        </EmptyState>
                    ) : (
                        <ul className="divide-y divide-line border-t border-line">
                            {workflows.map((w) => (
                                <li key={w.id}>
                                    <Link href={`/workflows/${w.id}`} className="flex items-center gap-4 px-5 py-3 hover:bg-surface-2">
                                        <AppStack apps={w.apps} />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-[13.5px] font-medium">{w.name}</p>
                                            <p className="text-[12px] text-fg-subtle">Edited {timeAgo(w.updated_at)}</p>
                                        </div>
                                        <span className={cn('flex items-center gap-1.5 text-[12px] font-medium', w.is_active ? 'text-ok' : 'text-fg-subtle')}>
                                            <span className={cn('size-1.5 rounded-full', w.is_active ? 'bg-ok' : 'bg-fg-subtle')} />{w.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <Card className="relative overflow-hidden p-5">
                    <div className="flex items-start gap-3">
                        <span className="flex size-10 items-center justify-center rounded-xl bg-primary-soft text-primary"><Blocks className="size-5" /></span>
                        <div>
                            <h2 className="text-[15px] font-semibold">{metrics.apps} apps ready to connect</h2>
                            <p className="mt-0.5 text-[13px] text-fg-muted">CRMs, chat, AI, payments, databases and more. Every app also gets an authenticated "Make an API call" action.</p>
                        </div>
                    </div>
                    <Button className="mt-4" onClick={() => router.visit('/apps')}>Browse apps<ArrowRight className="size-3.5" /></Button>
                </Card>
            </div>

            {templates.length > 0 && (
                <section className="mt-8">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-[15px] font-semibold">Start from a template</h2>
                        <Link href="/templates" className="flex items-center gap-1 text-[13px] font-medium text-fg-muted hover:text-primary">All templates<ArrowRight className="size-3.5" /></Link>
                    </div>
                    <div className="grid gap-4 md:grid-cols-3">
                        {templates.map((t) => <TemplateCard key={t.key} template={t} />)}
                    </div>
                </section>
            )}
        </AppLayout>
    );
}
