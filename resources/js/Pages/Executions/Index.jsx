import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ListChecks, Loader2, RotateCcw, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import ExecutionDebug from '@/Components/ExecutionDebug';
import { Button, Card, EmptyState, Select, Sheet, StatusBadge, Tabs } from '@/Components/ui';
import { cn, duration, errorMessage, timeAgo, toast } from '@/lib/utils';

const STATUSES = [['', 'All'], ['completed', 'Succeeded'], ['failed', 'Failed'], ['running', 'Running'], ['pending', 'Queued']];

export default function Index({ executions, filters, workflows }) {
    const [openId, setOpenId] = useState(() => Number(new URLSearchParams(window.location.search).get('open')) || null);

    const filter = (patch) => router.get('/executions', { ...filters, ...patch }, { preserveState: true, replace: true });

    // Queued runs wait for the next cron tick: refresh while any are still in flight.
    const inFlight = executions.data.some((e) => e.status === 'pending' || e.status === 'running');
    useEffect(() => {
        if (!inFlight) return;
        const timer = setInterval(() => router.reload({ only: ['executions'] }), 5000);
        return () => clearInterval(timer);
    }, [inFlight]);

    return (
        <AppLayout title="Executions" description="Every run, with each step's input, output and HTTP call. Queued runs start on the next scheduler tick.">
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Select value={filters.workflow ?? ''} onChange={(e) => filter({ workflow: e.target.value || undefined, page: undefined })} className="w-64" aria-label="Filter by workflow">
                    <option value="">All workflows</option>
                    {workflows.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                </Select>
                <Tabs value={filters.status ?? ''} onChange={(value) => filter({ status: value || undefined, page: undefined })}
                    items={STATUSES.map(([value, label]) => ({ value, label }))} />
                {inFlight && <span className="flex items-center gap-1.5 text-[12.5px] text-fg-muted"><Loader2 className="size-3.5 animate-spin" />Waiting for queued runs</span>}
            </div>

            {executions.data.length === 0 ? (
                <Card>
                    <EmptyState icon={ListChecks} title="No runs match">Runs appear here when a trigger fires or you test a workflow from the editor.</EmptyState>
                </Card>
            ) : (
                <Card className="overflow-hidden">
                    <table className="w-full text-left text-[13.5px]">
                        <thead className="border-b border-line bg-surface-2 text-[12.5px] text-fg-muted">
                            <tr>
                                <th className="w-32 px-4 py-2.5 font-medium">Status</th>
                                <th className="w-[45%] px-4 py-2.5 font-medium">Workflow</th>
                                <th className="hidden px-4 py-2.5 font-medium sm:table-cell">Started by</th>
                                <th className="hidden px-4 py-2.5 font-medium md:table-cell">When</th>
                                <th className="hidden px-4 py-2.5 text-right font-medium sm:table-cell">Duration</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {executions.data.map((execution) => (
                                <tr key={execution.id} onClick={() => setOpenId(execution.id)} className={cn('cursor-pointer hover:bg-surface-2/70', openId === execution.id && 'bg-primary-soft/50')}>
                                    <td className="px-4 py-3"><StatusBadge status={execution.status} /></td>
                                    <td className="max-w-0 px-4 py-3">
                                        <button type="button" className="block w-full truncate text-left font-medium hover:text-primary">#{execution.id} {execution.workflow.name}</button>
                                        {execution.error && <p className="truncate text-[12.5px] text-fail">{execution.error}</p>}
                                    </td>
                                    <td className="hidden px-4 py-3 text-fg-muted capitalize sm:table-cell">{execution.mode}</td>
                                    <td className="hidden px-4 py-3 text-fg-muted md:table-cell" title={execution.created_at}>{timeAgo(execution.created_at)}</td>
                                    <td className="hidden px-4 py-3 text-right text-fg-muted tabular-nums sm:table-cell">{duration(execution.duration_ms)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            )}

            {executions.last_page > 1 && (
                <nav className="mt-4 flex items-center justify-between text-[13px] text-fg-muted" aria-label="Pagination">
                    <span>Page {executions.current_page} of {executions.last_page}</span>
                    <div className="flex gap-2">
                        {executions.prev_page_url && <Link href={executions.prev_page_url} preserveState className="rounded-md border border-line px-3 py-1.5 hover:text-fg">Newer</Link>}
                        {executions.next_page_url && <Link href={executions.next_page_url} preserveState className="rounded-md border border-line px-3 py-1.5 hover:text-fg">Older</Link>}
                    </div>
                </nav>
            )}

            <DebugSheet id={openId} onClose={() => setOpenId(null)} />
        </AppLayout>
    );
}

function DebugSheet({ id, onClose }) {
    const [execution, setExecution] = useState(null);
    const [error, setError] = useState(null);
    const status = useRef(null);

    useEffect(() => {
        if (!id) return;
        let alive = true;
        setExecution(null);
        setError(null);
        status.current = null;
        const load = () => window.axios.get(`/executions/${id}`)
            .then(({ data }) => {
                if (!alive) return;
                status.current = data.execution.status;
                setExecution(data.execution);
            })
            .catch((e) => alive && setError(errorMessage(e)));
        load();
        // Keep refreshing only while the run is still queued or running.
        const timer = setInterval(() => ['pending', 'running'].includes(status.current) && load(), 4000);
        return () => { alive = false; clearInterval(timer); };
    }, [id]);

    const retry = () => router.post(`/executions/${id}/retry`, {}, { preserveScroll: true, onSuccess: onClose, onError: () => toast('Retry failed.', 'error') });

    return (
        <Sheet open={!!id} onClose={onClose}>
            <header className="flex items-center gap-3 border-b border-line px-5 py-4">
                <div className="min-w-0 flex-1">
                    <h2 className="truncate text-[16px] font-semibold">Run #{id}{execution && <> of {execution.workflow.name}</>}</h2>
                    {execution && (
                        <p className="text-[12.5px] text-fg-muted">
                            Started by {execution.mode}, {timeAgo(execution.created_at)}, took {duration(execution.duration_ms)}
                        </p>
                    )}
                </div>
                {execution && <StatusBadge status={execution.status} />}
                {execution && <Link href={`/workflows/${execution.workflow.id}`} className="hidden rounded-md px-2 py-1.5 text-[13px] text-fg-muted hover:bg-surface-2 hover:text-fg sm:inline">Open workflow</Link>}
                {execution && execution.status !== 'pending' && execution.status !== 'running' && (
                    <Button size="sm" onClick={retry}><RotateCcw className="size-3.5" />Retry</Button>
                )}
                <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close"><X className="size-4" /></Button>
            </header>
            {error && <p className="p-5 text-[13px] text-fail">{error}</p>}
            {!execution && !error && <p className="flex items-center gap-2 p-5 text-[13px] text-fg-muted"><Loader2 className="size-4 animate-spin" />Loading run…</p>}
            {execution && execution.steps.length === 0 && (
                <p className="p-5 text-[13px] text-fg-muted">{execution.status === 'pending' ? 'Queued: it starts on the next scheduler tick (at most a minute).' : execution.error ?? 'No steps were recorded.'}</p>
            )}
            {execution && execution.steps.length > 0 && <ExecutionDebug execution={execution} />}
        </Sheet>
    );
}
