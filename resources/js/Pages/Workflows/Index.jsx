import { useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Clock, Copy, MoreHorizontal, MousePointerClick, PencilLine, Plus, RefreshCw, Trash2, Webhook, Workflow as WorkflowIcon } from 'lucide-react';
import AppLayout, { openCreateWorkflow } from '@/Layouts/AppLayout';
import IntegrationIcon, { AppStack } from '@/Components/IntegrationIcon';
import { Button, Card, Dialog, EmptyState, Menu, SearchInput, Select, StatusBadge, Switch, Tabs } from '@/Components/ui';
import { errorMessage, timeAgo, toast } from '@/lib/utils';
import { useCan } from '@/lib/can';

const TRIGGERS = {
    webhook: { icon: Webhook, label: 'Webhook' },
    schedule: { icon: Clock, label: 'Schedule' },
    polling: { icon: RefreshCw, label: 'Polling' },
    manual: { icon: MousePointerClick, label: 'Manual' },
};

export default function Index({ workflows }) {
    const can = useCan();
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState('all');
    const [sort, setSort] = useState('updated');
    const [deleting, setDeleting] = useState(null);

    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();
        return workflows
            .filter((w) => status === 'all' || (status === 'active') === w.is_active)
            .filter((w) => !q || w.name.toLowerCase().includes(q) || (w.description ?? '').toLowerCase().includes(q) || w.apps.some((a) => a.name.toLowerCase().includes(q)))
            .sort((a, b) => (sort === 'name' ? a.name.localeCompare(b.name) : sort === 'created' ? b.created_at.localeCompare(a.created_at) : b.updated_at.localeCompare(a.updated_at)));
    }, [workflows, query, status, sort]);

    const active = workflows.filter((w) => w.is_active).length;

    return (
        <AppLayout title="Workflows" description="Everything you've built. Switch a workflow on to let its trigger run it in the background."
            actions={can['workflows.edit'] && <Button variant="primary" onClick={openCreateWorkflow}><Plus className="size-4" />Create workflow</Button>}>

            {workflows.length === 0 ? (
                <Card>
                    <EmptyState icon={WorkflowIcon} title="Build your first workflow"
                        action={<div className="flex gap-2"><Button onClick={() => router.visit('/templates')}>Browse templates</Button><Button variant="primary" onClick={openCreateWorkflow}><Plus className="size-4" />Create workflow</Button></div>}>
                        Pick a trigger, chain steps from {'500+'} apps, and test it on the canvas before switching it on.
                    </EmptyState>
                </Card>
            ) : (
                <>
                    <div className="mb-4 flex flex-wrap items-center gap-3">
                        <SearchInput value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search by name or app" className="w-full sm:w-72" aria-label="Search workflows" />
                        <Tabs value={status} onChange={setStatus} items={[
                            { value: 'all', label: 'All', count: workflows.length },
                            { value: 'active', label: 'Active', count: active },
                            { value: 'inactive', label: 'Inactive', count: workflows.length - active },
                        ]} />
                        <Select value={sort} onChange={(e) => setSort(e.target.value)} className="ml-auto w-48" aria-label="Sort workflows">
                            <option value="updated">Last updated</option>
                            <option value="created">Last created</option>
                            <option value="name">Name (A–Z)</option>
                        </Select>
                    </div>

                    <Card className="divide-y divide-line overflow-visible">
                        {visible.map((w) => <Row key={w.id} workflow={w} onDelete={() => setDeleting(w)} />)}
                        {visible.length === 0 && <p className="px-5 py-10 text-center text-[13px] text-fg-muted">No workflows match these filters.</p>}
                    </Card>
                </>
            )}

            <Dialog open={!!deleting} onClose={() => setDeleting(null)} title={`Delete "${deleting?.name}"?`} description="Its execution history is deleted too. This can't be undone.">
                <div className="flex justify-end gap-2">
                    <Button onClick={() => setDeleting(null)}>Cancel</Button>
                    <Button variant="danger" onClick={() => router.delete(`/workflows/${deleting.id}`, { onFinish: () => setDeleting(null) })}>Delete workflow</Button>
                </div>
            </Dialog>
        </AppLayout>
    );
}

function Row({ workflow, onDelete }) {
    const can = useCan();
    const [active, setActive] = useState(workflow.is_active);
    const trigger = TRIGGERS[workflow.trigger_type] ?? TRIGGERS.manual;

    const toggle = async (value) => {
        setActive(value);
        try {
            await window.axios.put(`/workflows/${workflow.id}`, { is_active: value });
            toast(value ? `"${workflow.name}" is live.` : `"${workflow.name}" paused.`, 'success');
        } catch (error) {
            setActive(!value);
            toast(errorMessage(error), 'error');
        }
    };

    return (
        <div className="group flex cursor-pointer items-center gap-4 px-5 py-4 transition-colors hover:bg-surface-2/60" onClick={() => router.visit(`/workflows/${workflow.id}`)}>
            <div className="hidden sm:block">
                <AppStack apps={workflow.apps} size="md" />
            </div>
            <div className="min-w-0 flex-1">
                <Link href={`/workflows/${workflow.id}`} className="block truncate text-[14px] font-semibold group-hover:text-primary" onClick={(e) => e.stopPropagation()}>{workflow.name}</Link>
                <p className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12.5px] text-fg-subtle">
                    <span className="inline-flex items-center gap-1"><trigger.icon className="size-3.5" />{trigger.label}</span>
                    <span>Updated {timeAgo(workflow.updated_at)}</span>
                    <span>Created {new Date(workflow.created_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}</span>
                </p>
            </div>
            <div className="hidden w-44 md:block">
                {workflow.last_run ? (
                    <div className="flex items-center gap-2">
                        <StatusBadge status={workflow.last_run.status} />
                        <span className="text-[12px] text-fg-subtle">{timeAgo(workflow.last_run.created_at)}</span>
                    </div>
                ) : <span className="text-[12px] text-fg-subtle">Never run</span>}
                <p className="mt-0.5 text-[12px] text-fg-subtle">{workflow.runs_24h} run{workflow.runs_24h === 1 ? '' : 's'} in 24h</p>
            </div>
            <div className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
                <span className="hidden text-[12.5px] font-medium text-fg-muted sm:inline">{active ? 'Active' : 'Inactive'}</span>
                {can['workflows.edit'] && <Switch checked={active} onChange={toggle} label={`Activate ${workflow.name}`} size="sm" />}
            </div>
            <Menu
                trigger={<Button variant="ghost" size="icon" aria-label={`More actions for ${workflow.name}`}><MoreHorizontal className="size-4" /></Button>}
                items={[
                    { label: can['workflows.edit'] ? 'Open editor' : 'Open', icon: PencilLine, onClick: () => router.visit(`/workflows/${workflow.id}`) },
                    can['workflows.edit'] && { label: 'Duplicate', icon: Copy, onClick: () => router.post(`/workflows/${workflow.id}/duplicate`) },
                    can['workflows.delete'] && { label: 'Delete', icon: Trash2, danger: true, onClick: onDelete },
                ]}
            />
        </div>
    );
}
