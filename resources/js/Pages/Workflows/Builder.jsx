import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { addEdge, Background, BackgroundVariant, MiniMap, ReactFlow, ReactFlowProvider, useEdgesState, useNodesState, useReactFlow } from '@xyflow/react';
import { ChevronDown, ChevronUp, Copy, FlaskConical, Loader2, MoreHorizontal, Plus, RotateCw, ScrollText, Trash2, Download, X } from 'lucide-react';
import AiIcon from '@/Components/AiIcon';
import ChatPanel from '@/Components/Assistant/ChatPanel';
import { BuilderContext, edgeTypes, nodeTypes } from '@/Components/Canvas/nodes';
import NodeCreator from '@/Components/Canvas/NodeCreator';
import FloatingToolbar from '@/Components/Canvas/FloatingToolbar';
import NodeDetails from '@/Components/Drawer/NodeDetails';
import ExecutionDebug from '@/Components/ExecutionDebug';
import { Button, Dialog, Menu, StatusBadge, Switch, Tabs } from '@/Components/ui';
import { Logo, Toaster } from '@/Layouts/AppLayout';
import { isDark } from '@/lib/theme';
import { useCan } from '@/lib/can';
import { cn, duration, errorMessage, timeAgo, toast } from '@/lib/utils';

const toFlowNode = (node) => ({ ...node, deletable: node.type !== 'trigger' });
const toFlowEdge = (edge) => ({ ...edge, type: 'deletable' });

/** Only what the engine stores; React Flow adds selection/measurement state we don't want to save. */
const serialize = (nodes, edges) => ({
    nodes: nodes.map(({ id, type, position, data }) => ({ id, type, position, data })),
    edges: edges.map(({ id, source, target, sourceHandle }) => ({ id, source, target, sourceHandle: sourceHandle ?? null })),
});

function reaches(edges, from, to) {
    const stack = [from];
    const seen = new Set();
    while (stack.length) {
        const id = stack.pop();
        if (id === to) return true;
        if (seen.has(id)) continue;
        seen.add(id);
        edges.forEach((e) => e.source === id && stack.push(e.target));
    }
    return false;
}

const ancestors = (edges, id) => {
    const result = [id];
    for (let i = 0; i < result.length; i++) {
        edges.forEach((e) => e.target === result[i] && !result.includes(e.source) && result.push(e.source));
    }
    return result;
};

function parseJson(text) {
    try { return typeof text === 'string' && text.trim() ? JSON.parse(text) : undefined; } catch { return undefined; }
}

export default function Builder(props) {
    return (
        <ReactFlowProvider>
            <Head title={props.workflow.name} />
            <Editor {...props} />
            <Toaster />
        </ReactFlowProvider>
    );
}

function Editor({ workflow: initial, catalog, operators, lastExecution, ai }) {
    const { screenToFlowPosition, fitView } = useReactFlow();
    const can = useCan();
    const readOnly = !can['workflows.edit']; // view-only team members: look, never change or run
    const catalogMap = useMemo(() => Object.fromEntries(catalog.map((i) => [i.key, i])), [catalog]);

    const [nodes, setNodes, onNodesChange] = useNodesState((initial.graph?.nodes ?? []).map(toFlowNode));
    const [edges, setEdges, onEdgesChange] = useEdgesState((initial.graph?.edges ?? []).map(toFlowEdge));
    const [name, setName] = useState(initial.name);
    const [workflow, setWorkflow] = useState(initial);
    const [tab, setTab] = useState('editor');
    const [creator, setCreator] = useState(null);
    const [openId, setOpenId] = useState(null);
    const [execution, setExecution] = useState(lastExecution);
    const [logsOpen, setLogsOpen] = useState(false);
    const [running, setRunning] = useState(null);
    const [save, setSave] = useState({ state: 'saved' });
    const [deleting, setDeleting] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [autoRetry, setAutoRetry] = useState(initial.settings?.auto_retry ?? true);
    const saveAutoRetry = async (value) => {
        setAutoRetry(value);
        try {
            await window.axios.put(`/workflows/${initial.id}`, { settings: { auto_retry: value } });
        } catch (error) {
            setAutoRetry(!value);
            toast(errorMessage(error), 'error');
        }
    };
    const [dark, setDark] = useState(isDark);
    const [aiOpen, setAiOpen] = useState(false);
    const [aiConversation, setAiConversation] = useState(null);

    useEffect(() => {
        const handler = (e) => setDark(e.detail === 'dark');
        window.addEventListener('ff:theme', handler);
        return () => window.removeEventListener('ff:theme', handler);
    }, []);

    // ---- autosave ---------------------------------------------------------------------------
    const graph = useMemo(() => serialize(nodes, edges), [nodes, edges]);
    const lastSaved = useRef(JSON.stringify({ name: initial.name, graph: serialize(initial.graph?.nodes ?? [], initial.graph?.edges ?? []) }));

    const persist = useCallback(async () => {
        if (readOnly) return true;
        const body = { name: name.trim() || 'Untitled workflow', graph };
        const key = JSON.stringify(body);
        if (key === lastSaved.current) return true;
        setSave({ state: 'saving' });
        try {
            const { data } = await window.axios.put(`/workflows/${initial.id}`, body);
            lastSaved.current = key;
            setWorkflow((w) => ({ ...w, ...data.workflow }));
            setSave({ state: 'saved' });
            return true;
        } catch (error) {
            setSave({ state: 'error', message: errorMessage(error) });
            return false;
        }
    }, [name, graph, initial.id, readOnly]);

    useEffect(() => {
        const timer = setTimeout(persist, 800);
        return () => clearTimeout(timer);
    }, [persist]);

    useEffect(() => {
        const warn = (event) => save.state !== 'saved' && event.preventDefault();
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [save.state]);

    // The assistant edited this workflow on the server: reload props and adopt the new graph.
    useEffect(() => {
        setNodes((initial.graph?.nodes ?? []).map(toFlowNode));
        setEdges((initial.graph?.edges ?? []).map(toFlowEdge));
        setName(initial.name);
        setWorkflow(initial);
        lastSaved.current = JSON.stringify({ name: initial.name, graph: serialize(initial.graph?.nodes ?? [], initial.graph?.edges ?? []) });
    }, [initial.updated_at]); // eslint-disable-line react-hooks/exhaustive-deps

    const onAssistantChange = (actions) => {
        if (actions.some((a) => a.type === 'deleted' && a.workflow_id === initial.id)) return router.visit('/workflows');
        if (actions.some((a) => a.workflow_id === initial.id)) router.reload({ only: ['workflow'] });
    };

    // ---- graph editing ----------------------------------------------------------------------
    const nextId = () => `step_${Math.max(0, ...nodes.map((n) => Number(n.id.replace('step_', '')) || 0)) + 1}`;

    const freeSpot = (x, y) => {
        let yy = y;
        while (nodes.some((n) => Math.abs(n.position.x - x) < 160 && Math.abs(n.position.y - yy) < 140)) yy += 150;
        return { x, y: yy };
    };

    const pickFromCreator = (payload) => {
        if (creator?.mode === 'trigger') {
            const entry = catalogMap[payload.integration];
            updateNode('trigger', { integration: payload.integration, trigger: payload.trigger, config: {}, credential_id: null, label: entry?.triggers.find((t) => t.key === payload.trigger)?.name });
            setCreator(null);
            setOpenId('trigger');
            return;
        }

        const id = nextId();
        const from = creator?.from ? nodes.find((n) => n.id === creator.from.id) : null;
        const anchor = from ?? nodes.reduce((right, n) => (!right || n.position.x > right.position.x ? n : right), null);
        const offsetY = creator?.from?.handle === 'true' ? -80 : creator?.from?.handle === 'false' ? 80 : 0;
        const position = creator?.position ?? freeSpot((anchor?.position.x ?? 0) + 220, (anchor?.position.y ?? 0) + offsetY);
        const actionName = catalogMap[payload.integration]?.actions.find((a) => a.key === payload.action)?.name;

        setNodes((nds) => [...nds.map((n) => ({ ...n, selected: false })), {
            id, type: payload.branch ? 'branch' : 'action', position, selected: true,
            data: { integration: payload.integration, action: payload.action, config: {}, label: actionName },
        }]);
        if (from) {
            setEdges((eds) => addEdge({ id: `e-${from.id}-${id}-${creator.from.handle ?? 'out'}`, source: from.id, target: id, sourceHandle: creator.from.handle ?? null, type: 'deletable' }, eds));
        }
        setCreator(null);
        setOpenId(id);
    };

    const updateNode = useCallback((id, patch, type) => {
        setNodes((nds) => nds.map((n) => (n.id === id ? { ...n, ...(type ? { type } : {}), data: { ...n.data, ...patch } } : n)));
        if (type) setEdges((eds) => eds.filter((e) => e.source !== id)); // output handles changed shape
    }, [setNodes, setEdges]);

    const toggleNode = useCallback((id) => {
        setNodes((nds) => nds.map((n) => (n.id === id ? { ...n, data: { ...n.data, disabled: n.data.disabled ? undefined : true } } : n)));
    }, [setNodes]);

    const removeNode = useCallback((id) => {
        if (id === 'trigger') return;
        setNodes((nds) => nds.filter((n) => n.id !== id));
        setEdges((eds) => eds.filter((e) => e.source !== id && e.target !== id));
        setOpenId((current) => (current === id ? null : current));
    }, [setNodes, setEdges]);

    const isValidConnection = useCallback((c) => {
        if (c.source === c.target) return false;
        if (nodes.find((n) => n.id === c.target)?.type === 'trigger') return false;
        return !reaches(edges, c.target, c.source); // would close a loop
    }, [nodes, edges]);

    const onConnect = useCallback((params) => setEdges((eds) => addEdge({ ...params, type: 'deletable', id: `e-${params.source}-${params.target}-${params.sourceHandle ?? 'out'}` }, eds)), [setEdges]);

    // ---- running ----------------------------------------------------------------------------
    const runSteps = useMemo(() => Object.fromEntries((execution?.steps ?? []).map((s) => [s.node_id, s])), [execution]);

    const executeStep = useCallback(async (nodeId = null) => {
        if (!(await persist())) return toast('Fix the save error before running.', 'error');
        setRunning(nodeId ? ancestors(edges, nodeId) : 'all');
        try {
            const { data } = await window.axios.post(`/workflows/${initial.id}/test`, nodeId ? { node: nodeId } : {});
            setExecution(data.execution);
            if (!nodeId) setLogsOpen(true);
            const ok = data.execution.status === 'completed';
            toast(ok ? `${nodeId ? 'Step' : 'Workflow'} executed in ${duration(data.execution.duration_ms)}.` : 'Execution failed. See the red step.', ok ? 'success' : 'error');
        } catch (error) {
            toast(errorMessage(error), 'error');
        } finally {
            setRunning(null);
        }
    }, [persist, edges, initial.id]);

    const toggleActive = async (value) => {
        if (!(await persist())) return toast('Fix the save error first.', 'error');
        try {
            const { data } = await window.axios.put(`/workflows/${initial.id}`, { is_active: value });
            setWorkflow((w) => ({ ...w, ...data.workflow }));
            toast(value ? 'Workflow is live. Its trigger now runs it in the background.' : 'Workflow paused.', 'success');
        } catch (error) {
            toast(errorMessage(error), 'error');
        }
    };

    // The server's export leaves out credentials and passwords, and carries any custom apps the workflow uses.
    const download = async () => {
        if (!readOnly) await persist();
        window.location.href = `/workflows/${initial.id}/export`;
    };

    // ---- derived view state -----------------------------------------------------------------
    const connected = useMemo(() => new Set(edges.map((e) => `${e.source}:${e.sourceHandle ?? ''}`)), [edges]);

    const styledEdges = useMemo(() => {
        if (!execution || running) return edges;
        return edges.map((e) => {
            const source = runSteps[e.source];
            const target = runSteps[e.target];
            const ran = source?.status === 'success' && !source.halted && (!source.handle || source.handle === e.sourceHandle) && target && target.status !== 'skipped';
            return { ...e, className: ran ? 'ff-ran' : 'ff-idle' };
        });
    }, [edges, execution, runSteps, running]);

    const openNode = nodes.find((n) => n.id === openId) ?? null;

    const upstream = useMemo(() => {
        if (!openNode) return [];
        return ancestors(edges, openNode.id).slice(1).reverse().map((id) => {
            const node = nodes.find((n) => n.id === id);
            if (!node) return null;
            const integration = catalogMap[node.data.integration];
            let sample = runSteps[id]?.status === 'success' ? runSteps[id].output : undefined;
            if (sample === undefined && node.type === 'trigger') {
                const configured = parseJson(node.data.config?.sample_payload);
                sample = configured !== undefined ? { data: configured } : workflow.trigger_state?.sample;
            }
            return { id, label: node.data.label || id, icon: integration?.icon, integrationName: integration?.name, sample };
        }).filter(Boolean);
    }, [openNode, edges, nodes, catalogMap, runSteps, workflow.trigger_state]);

    const context = useMemo(() => ({
        catalog: catalogMap, runSteps: execution ? runSteps : {}, running, connected,
        openCreator: (opts) => setCreator({ mode: 'action', ...opts }),
        openNode: setOpenId, executeStep, removeNode, toggleNode, readOnly,
    }), [catalogMap, execution, runSteps, running, connected, executeStep, removeNode, toggleNode, readOnly]);

    const onDrop = (event) => event.preventDefault();

    return (
        <BuilderContext.Provider value={context}>
            <div className="flex h-dvh flex-col bg-bg">
                <header className="flex h-14 shrink-0 items-center gap-3 border-b border-line bg-surface px-3 sm:px-4">
                    <Link href="/" aria-label="FlowForge home"><Logo className="size-7" /></Link>
                    <nav className="flex min-w-0 items-center gap-1.5 text-[14px]" aria-label="Breadcrumb">
                        <Link href="/workflows" className="hidden text-fg-muted hover:text-fg sm:inline">Workflows</Link>
                        <span className="hidden text-fg-subtle sm:inline">/</span>
                        <input value={name} onChange={(e) => setName(e.target.value)} aria-label="Workflow name" readOnly={readOnly}
                            className="min-w-0 truncate rounded-md bg-transparent px-1.5 py-1 text-base font-semibold sm:text-[14px] outline-none hover:bg-surface-2 focus:bg-surface-2 sm:w-72" />
                    </nav>
                    <span className={cn('hidden text-[12.5px] lg:inline', save.state === 'error' ? 'text-fail' : 'text-fg-subtle')} title={save.message} aria-live="polite">
                        {readOnly ? 'View only' : save.state === 'saving' ? 'Saving…' : save.state === 'error' ? 'Not saved' : 'Saved'}
                    </span>
                    <div className="mx-auto hidden md:block">
                        <Tabs value={tab} onChange={setTab} items={[{ value: 'editor', label: 'Editor' }, { value: 'executions', label: 'Executions' }]} />
                    </div>
                    <div className="ml-auto flex items-center gap-2.5 md:ml-0">
                        {can.ai && <Button size="sm" variant={aiOpen ? 'subtle' : 'secondary'} onClick={() => setAiOpen((o) => !o)}><AiIcon className="size-4 text-primary" />Ask AI</Button>}
                        <label className="flex items-center gap-2 text-[13px] font-medium text-fg-muted">
                            <span className="hidden sm:inline">{workflow.is_active ? 'Active' : 'Inactive'}</span>
                            {!readOnly && <Switch checked={workflow.is_active} onChange={toggleActive} label="Activate workflow" />}
                        </label>
                        <Menu trigger={<Button variant="ghost" size="icon" aria-label="Workflow menu"><MoreHorizontal className="size-4" /></Button>} items={[
                            !readOnly && { label: 'Duplicate', icon: Copy, onClick: async () => { await persist(); router.post(`/workflows/${initial.id}/duplicate`); } },
                            { label: 'Retries and errors', icon: RotateCw, onClick: () => setSettingsOpen(true) },
                            { label: 'Export', icon: Download, onClick: download },
                            can['workflows.delete'] && { label: 'Delete workflow', icon: Trash2, danger: true, onClick: () => setDeleting(true) },
                        ]} />
                    </div>
                </header>

                {tab === 'executions' ? (
                    <ExecutionsTab workflowId={initial.id} onOpenInEditor={(e) => { setExecution(e); setTab('editor'); setLogsOpen(true); }} />
                ) : (
                    <div className="relative flex min-h-0 flex-1 flex-col">
                        <div className="relative min-h-0 flex-1" onDragOver={(e) => e.preventDefault()} onDrop={onDrop}>
                            <ReactFlow
                                nodes={nodes}
                                edges={styledEdges}
                                nodeTypes={nodeTypes}
                                edgeTypes={edgeTypes}
                                onNodesChange={onNodesChange}
                                onEdgesChange={onEdgesChange}
                                onConnect={readOnly ? undefined : onConnect}
                                nodesDraggable={!readOnly}
                                nodesConnectable={!readOnly}
                                edgesReconnectable={!readOnly}
                                isValidConnection={isValidConnection}
                                onNodeClick={(_, node) => setOpenId(node.id)}
                                onPaneClick={() => setCreator(null)}
                                colorMode={dark ? 'dark' : 'light'}
                                fitView
                                fitViewOptions={{ padding: 0.35, maxZoom: 1.1 }}
                                minZoom={0.2}
                                maxZoom={1.75}
                                deleteKeyCode={readOnly ? null : ['Backspace', 'Delete']}
                                defaultEdgeOptions={{ type: 'deletable' }}
                                proOptions={{ hideAttribution: false }}
                            >
                                <Background variant={BackgroundVariant.Dots} gap={20} size={1.5} />
                                <MiniMap pannable zoomable className="!hidden !rounded-xl !border !border-line !shadow-card lg:!block" nodeBorderRadius={14} />
                                <FloatingToolbar />
                            </ReactFlow>

                            {!readOnly && <div className="absolute top-4 right-4 z-10 flex flex-col gap-2">
                                <button type="button" onClick={() => setCreator({ mode: 'action' })} title="Add step" aria-label="Add step"
                                    className="flex size-10 items-center justify-center rounded-xl border border-line bg-surface text-fg shadow-card hover:border-primary hover:text-primary">
                                    <Plus className="size-5" />
                                </button>
                            </div>}

                            <div className="absolute bottom-5 left-1/2 z-10 flex -translate-x-1/2 items-center gap-2">
                                {!readOnly && (
                                    <Button variant="primary" size="lg" onClick={() => executeStep(null)} disabled={!!running} className="shadow-pop">
                                        {running === 'all' ? <Loader2 className="size-4 animate-spin" /> : <FlaskConical className="size-4" />}
                                        {running === 'all' ? 'Executing…' : 'Execute workflow'}
                                    </Button>
                                )}
                                {execution && (
                                    <Button size="lg" onClick={() => setLogsOpen((o) => !o)} className="shadow-pop">
                                        <ScrollText className="size-4" />Logs<StatusBadge status={execution.status} />
                                    </Button>
                                )}
                            </div>

                            {creator && <NodeCreator catalog={catalog} mode={creator.mode} onPick={pickFromCreator} onClose={() => setCreator(null)} />}
                            {aiOpen && (
                                <aside className="animate-slide-in absolute inset-y-0 right-0 z-30 flex w-[min(420px,100%)] flex-col border-l border-line bg-surface shadow-pop" aria-label="AI builder">
                                    <div className="flex items-center gap-2 border-b border-line px-4 py-3">
                                        <AiIcon className="size-4 text-primary" />
                                        <h2 className="flex-1 text-[14px] font-semibold">Ask AI about this workflow</h2>
                                        {ai && <span className="truncate text-[11.5px] text-fg-subtle">{ai.model}</span>}
                                        <button type="button" onClick={() => setAiOpen(false)} className="rounded p-1 text-fg-muted hover:bg-surface-2" aria-label="Close"><X className="size-4" /></button>
                                    </div>
                                    <ChatPanel compact configured={!!ai} workflowId={initial.id} conversationId={aiConversation}
                                        beforeSend={async () => { if (!(await persist())) throw new Error('Save failed. Fix the save error first.'); }}
                                        onConversation={(c) => setAiConversation(c.id)} onWorkflowChanged={onAssistantChange} />
                                </aside>
                            )}
                        </div>

                        {execution && logsOpen && (
                            <section className="flex h-[40vh] min-h-64 shrink-0 flex-col border-t border-line bg-surface" aria-label="Logs">
                                <div className="flex items-center gap-3 border-b border-line px-4 py-2">
                                    <h2 className="text-[13px] font-semibold">Logs</h2>
                                    <StatusBadge status={execution.status} />
                                    <span className="text-[12.5px] text-fg-subtle">Run #{execution.id}, {execution.mode}, {duration(execution.duration_ms)}, {timeAgo(execution.created_at)}</span>
                                    {execution.error && <span className="hidden truncate text-[12.5px] text-fail xl:inline">{execution.error}</span>}
                                    <button type="button" onClick={() => setLogsOpen(false)} className="ml-auto rounded-md p-1 text-fg-muted hover:bg-surface-2 hover:text-fg" aria-label="Hide logs"><ChevronDown className="size-4" /></button>
                                </div>
                                <ExecutionDebug execution={execution} compact />
                            </section>
                        )}
                        {execution && !logsOpen && (
                            <button type="button" onClick={() => setLogsOpen(true)} className="flex h-9 shrink-0 items-center gap-2 border-t border-line bg-surface px-4 text-[12.5px] text-fg-muted hover:text-fg">
                                <ChevronUp className="size-4" />Logs<span className="text-fg-subtle">Run #{execution.id} {execution.status === 'completed' ? 'succeeded' : execution.status}, {timeAgo(execution.created_at)}</span>
                            </button>
                        )}
                    </div>
                )}
            </div>

            {openNode && (
                <NodeDetails
                    key={openNode.id}
                    node={openNode}
                    catalog={catalog}
                    operators={operators}
                    workflow={workflow}
                    upstream={upstream}
                    runStep={runSteps[openNode.id]}
                    executing={!!running}
                    onChange={(patch, type) => updateNode(openNode.id, patch, type)}
                    onExecute={executeStep}
                    onDelete={() => removeNode(openNode.id)}
                    onClose={() => setOpenId(null)}
                    readOnly={readOnly}
                />
            )}

            <Dialog open={settingsOpen} onClose={() => setSettingsOpen(false)} title="Retries and errors" description="What happens when an app is busy, slow or down while this workflow runs.">
                <div className="space-y-4 text-[13.5px]">
                    <label className="flex items-start justify-between gap-4">
                        <span>
                            <span className="block font-semibold">Retry failed runs automatically</span>
                            <span className="mt-0.5 block text-[12.5px] text-fg-muted">If a run fails because an app is down, slow or rate-limited, FlowForge tries again from the step that failed: after 2 minutes, 10 minutes, 1 hour, 3 hours and 6 hours. Steps that already worked aren't repeated, and you only get the failure email if every try fails.</span>
                        </span>
                        <Switch checked={autoRetry} onChange={saveAutoRetry} disabled={readOnly} label="Retry failed runs automatically" />
                    </label>
                    <p className="rounded-lg bg-surface-2 px-3 py-2.5 text-[12.5px] text-fg-muted">Each step can also try again right away, keep going when it fails, or be turned off: open the step and look under <strong className="text-fg">When this step runs</strong>.</p>
                </div>
            </Dialog>
            <Dialog open={deleting} onClose={() => setDeleting(false)} title={`Delete "${name}"?`} description="Its execution history is deleted too. This can't be undone.">
                <div className="flex justify-end gap-2">
                    <Button onClick={() => setDeleting(false)}>Cancel</Button>
                    <Button variant="danger" onClick={() => router.delete(`/workflows/${initial.id}`)}>Delete workflow</Button>
                </div>
            </Dialog>
        </BuilderContext.Provider>
    );
}

/** Runs of this workflow: list on the left, step debugger on the right. */
function ExecutionsTab({ workflowId, onOpenInEditor }) {
    const [runs, setRuns] = useState(null);
    const [selected, setSelected] = useState(null);
    const [detail, setDetail] = useState(null);

    useEffect(() => {
        window.axios.get(`/workflows/${workflowId}/runs`).then(({ data }) => {
            setRuns(data.executions);
            setSelected(data.executions[0]?.id ?? null);
        });
    }, [workflowId]);

    useEffect(() => {
        if (!selected) return;
        setDetail(null);
        window.axios.get(`/executions/${selected}`).then(({ data }) => setDetail(data.execution));
    }, [selected]);

    if (!runs) return <p className="flex flex-1 items-center justify-center gap-2 text-[13px] text-fg-muted"><Loader2 className="size-4 animate-spin" />Loading runs…</p>;
    if (runs.length === 0) return <p className="flex flex-1 items-center justify-center text-[13px] text-fg-muted">No runs yet. Execute the workflow from the editor, or activate it.</p>;

    return (
        <div className="flex min-h-0 flex-1">
            <ul className="w-72 shrink-0 overflow-y-auto border-r border-line bg-surface p-2">
                {runs.map((run) => (
                    <li key={run.id}>
                        <button type="button" onClick={() => setSelected(run.id)} aria-current={selected === run.id}
                            className={cn('w-full rounded-lg px-3 py-2.5 text-left', selected === run.id ? 'bg-primary-soft' : 'hover:bg-surface-2')}>
                            <span className="flex items-center justify-between gap-2">
                                <span className="text-[13px] font-medium">{new Date(run.created_at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })}</span>
                                <StatusBadge status={run.status} />
                            </span>
                            <span className="mt-0.5 block text-[12px] text-fg-subtle capitalize">#{run.id}, {run.mode}, {duration(run.duration_ms)}</span>
                        </button>
                    </li>
                ))}
            </ul>
            <div className="flex min-w-0 flex-1 flex-col bg-surface">
                {detail ? (
                    <>
                        <div className="flex items-center gap-3 border-b border-line px-5 py-3">
                            <h2 className="text-[14px] font-semibold">Run #{detail.id}</h2>
                            <StatusBadge status={detail.status} />
                            <Button size="sm" className="ml-auto" onClick={() => onOpenInEditor(detail)}>Show on canvas</Button>
                        </div>
                        <ExecutionDebug execution={detail} />
                    </>
                ) : <p className="flex flex-1 items-center justify-center gap-2 text-[13px] text-fg-muted"><Loader2 className="size-4 animate-spin" />Loading…</p>}
            </div>
        </div>
    );
}
