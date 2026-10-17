import { cloneElement, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { addEdge, Background, BackgroundVariant, MiniMap, ReactFlow, ReactFlowProvider, useEdgesState, useNodesState, useReactFlow } from '@xyflow/react';
import {
    BetweenHorizontalStart, ChevronDown, ChevronUp, ClipboardPaste, Copy, CopyPlus, FlaskConical, Loader2, Maximize, MoreHorizontal, Play, Plus, Power, RotateCw,
    ScrollText, Settings2, SquareDashedMousePointer, Trash2, Download, Unplug, X, Zap,
} from 'lucide-react';
import AiIcon from '@/Components/AiIcon';
import ChatPanel from '@/Components/Assistant/ChatPanel';
import { BuilderContext, edgeTypes, nodeTypes } from '@/Components/Canvas/nodes';
import NodeCreator from '@/Components/Canvas/NodeCreator';
import CanvasMenu, { MOD } from '@/Components/Canvas/CanvasMenu';
import FloatingToolbar from '@/Components/Canvas/FloatingToolbar';
import NodeDetails from '@/Components/Drawer/NodeDetails';
import ExecutionDebug from '@/Components/ExecutionDebug';
import { Button, Dialog, Menu, StatusBadge, Switch, Tabs, usePresence } from '@/Components/ui';
import { Logo, Toaster } from '@/Layouts/AppLayout';
import { isDark } from '@/lib/theme';
import { useCan } from '@/lib/can';
import { cn, duration, errorMessage, timeAgo, toast } from '@/lib/utils';

const MAIN_TRIGGER = 'trigger';
// Extra triggers (trigger_2, trigger_3…) each start their own flow and can be deleted; the main one can't.
const toFlowNode = (node) => ({ ...node, deletable: node.id !== MAIN_TRIGGER });
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

const descendants = (edges, id) => {
    const result = [id];
    for (let i = 0; i < result.length; i++) {
        edges.forEach((e) => e.source === result[i] && !result.includes(e.target) && result.push(e.target));
    }
    return result;
};

/*
 * Copy and paste of steps, between workflows too (kept in this browser). Pasted steps get fresh ids, and their
 * {{ steps.<id> }} references to each other follow the new ids. A copied trigger becomes a new flow's trigger.
 */
const CLIPBOARD = 'ff:canvas-clipboard';
const readClipboard = () => {
    try { return JSON.parse(localStorage.getItem(CLIPBOARD)) ?? null; } catch { return null; }
};
const writeClipboard = (clip) => {
    try { localStorage.setItem(CLIPBOARD, JSON.stringify(clip)); } catch { /* private mode: copy still works within this tab via duplicate */ }
};
const clipOf = (picked, edges) => {
    const ids = new Set(picked.map((n) => n.id));
    return {
        nodes: picked.map(({ id, type, position, data }) => ({ id, type, position, data })),
        edges: edges.filter((e) => ids.has(e.source) && ids.has(e.target)).map(({ source, target, sourceHandle }) => ({ source, target, sourceHandle: sourceHandle ?? null })),
    };
};
const numberOf = (id, prefix) => (id === prefix ? 1 : Number(id.match(new RegExp(`^${prefix}_(\\d+)$`))?.[1] ?? 0));

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
    const { screenToFlowPosition, fitView, deleteElements } = useReactFlow();
    const can = useCan();
    const readOnly = !can['workflows.edit']; // view-only team members: look, never change or run
    const catalogMap = useMemo(() => Object.fromEntries(catalog.map((i) => [i.key, i])), [catalog]);

    const [nodes, setNodes, onNodesChange] = useNodesState((initial.graph?.nodes ?? []).map(toFlowNode));
    const [edges, setEdges, onEdgesChange] = useEdgesState((initial.graph?.edges ?? []).map(toFlowEdge));
    const [name, setName] = useState(initial.name);
    const [workflow, setWorkflow] = useState(initial);
    const [tab, setTab] = useState('editor');
    const [creator, setCreator] = useState(null);
    const [menu, setMenu] = useState(null); // the right-click menu
    const closeMenu = useCallback(() => setMenu(null), []);
    const [openId, setOpenId] = useState(null);
    // Where the last press happened: the step editor grows out of the step that was clicked.
    const lastPointer = useRef(null);
    const [origin, setOrigin] = useState(null);
    const openStep = useCallback((id) => { setOrigin(lastPointer.current); setOpenId(id); }, []);
    const [execution, setExecution] = useState(lastExecution);
    const [logsOpen, setLogsOpen] = useState(false);
    const [running, setRunning] = useState(null);
    const [save, setSave] = useState({ state: 'saved' });
    const [deleting, setDeleting] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [retry, setRetry] = useState({ auto_retry: initial.settings?.auto_retry ?? true, retry_on: initial.settings?.retry_on ?? 'temporary' });
    const saveRetry = async (patch) => {
        const before = retry;
        setRetry({ ...retry, ...patch });
        try {
            await window.axios.put(`/workflows/${initial.id}`, { settings: patch });
            if ('auto_retry' in patch) toast(patch.auto_retry ? 'Auto-retry is on for this workflow.' : 'Auto-retry is off for this workflow.', 'success');
        } catch (error) {
            setRetry(before);
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
    const nextId = () => `step_${Math.max(0, ...nodes.map((n) => numberOf(n.id, 'step'))) + 1}`;
    const nextTriggerId = () => `trigger_${Math.max(1, ...nodes.map((n) => numberOf(n.id, MAIN_TRIGGER))) + 1}`;

    const freeSpot = (x, y) => {
        let yy = y;
        while (nodes.some((n) => Math.abs(n.position.x - x) < 160 && Math.abs(n.position.y - yy) < 140)) yy += 150;
        return { x, y: yy };
    };

    const pickFromCreator = (payload) => {
        if (creator?.mode === 'trigger') {
            const entry = catalogMap[payload.integration];
            const data = { integration: payload.integration, trigger: payload.trigger, config: {}, credential_id: null, label: entry?.triggers.find((t) => t.key === payload.trigger)?.name };
            if (creator.newFlow) {
                // Another trigger: the start of a new, separate flow in this workflow.
                const id = nextTriggerId();
                const lowest = nodes.reduce((y, n) => Math.max(y, n.position.y), 0);
                setNodes((nds) => [...nds.map((n) => ({ ...n, selected: false })), { id, type: 'trigger', deletable: true, selected: true, data, position: creator.position ?? { x: 80, y: lowest + 260 } }]);
                setCreator(null);
                openStep(id);
                return;
            }
            updateNode(creator.target ?? MAIN_TRIGGER, data);
            setCreator(null);
            setOpenId(creator.target ?? MAIN_TRIGGER);
            return;
        }

        if (creator?.insert) {
            // Into an existing connection: source → new step → target.
            const edge = creator.insert;
            const [a, b] = [nodes.find((n) => n.id === edge.source), nodes.find((n) => n.id === edge.target)];
            const id = nextId();
            const actionName = catalogMap[payload.integration]?.actions.find((x) => x.key === payload.action)?.name;
            const position = a && b ? { x: (a.position.x + b.position.x) / 2, y: (a.position.y + b.position.y) / 2 + 60 } : creator.position;
            setNodes((nds) => [...nds.map((n) => ({ ...n, selected: false })), { id, type: payload.branch ? 'branch' : 'action', position, selected: true,
                data: { integration: payload.integration, action: payload.action, config: {}, label: actionName } }]);
            setEdges((eds) => [
                ...eds.filter((e) => e.id !== edge.id),
                { id: `e-${edge.source}-${id}-${edge.sourceHandle ?? 'out'}`, source: edge.source, target: id, sourceHandle: edge.sourceHandle ?? null, type: 'deletable' },
                { id: `e-${id}-${edge.target}-${payload.branch ? 'true' : 'out'}`, source: id, target: edge.target, sourceHandle: payload.branch ? 'true' : null, type: 'deletable' },
            ]);
            setCreator(null);
            setOpenId(id);
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

    // Deleting steps is one click; the toast offers Undo for a few seconds instead of asking first.
    const offerUndo = useCallback((goneNodes, goneEdges) => {
        if (!goneNodes.length) return;
        const what = goneNodes.length === 1 ? `"${goneNodes[0].data?.label || 'Step'}" deleted.` : `${goneNodes.length} steps deleted.`;
        toast(what, 'info', { action: { label: 'Undo', onClick: () => {
            setNodes((nds) => [...nds, ...goneNodes.filter((g) => !nds.some((n) => n.id === g.id))]);
            setEdges((eds) => [...eds, ...goneEdges.filter((g) => !eds.some((e) => e.id === g.id))]);
        } } });
    }, [setNodes, setEdges]);

    const removeNode = useCallback((id) => {
        if (id === 'trigger') return;
        const gone = nodes.filter((n) => n.id === id);
        const goneEdges = edges.filter((e) => e.source === id || e.target === id);
        setNodes((nds) => nds.filter((n) => n.id !== id));
        setEdges((eds) => eds.filter((e) => e.source !== id && e.target !== id));
        setOpenId((current) => (current === id ? null : current));
        offerUndo(gone, goneEdges);
    }, [nodes, edges, setNodes, setEdges, offerUndo]);

    // ---- copy, paste, duplicate, select ---------------------------------------------------------
    const selectedNodes = () => nodes.filter((n) => n.selected);

    /** Adds copies of clipped steps with fresh ids, at `at` (flow position) or offset from the originals. */
    const pasteClip = (clip, at = null) => {
        if (!clip?.nodes?.length) return;
        let stepN = Math.max(0, ...nodes.map((n) => numberOf(n.id, 'step')));
        let triggerN = Math.max(1, ...nodes.map((n) => numberOf(n.id, MAIN_TRIGGER)));
        const ids = Object.fromEntries(clip.nodes.map((n) => [n.id, n.type === 'trigger' ? `trigger_${++triggerN}` : `step_${++stepN}`]));
        const left = Math.min(...clip.nodes.map((n) => n.position.x));
        const top = Math.min(...clip.nodes.map((n) => n.position.y));
        const shift = at ? { x: at.x - left, y: at.y - top } : { x: 60, y: 60 };
        // {{ steps.step_1.x }} inside the copies points at the copy of step_1 (\b keeps step_1 from matching step_10).
        const rewire = (data) => JSON.parse(Object.entries(ids).reduce(
            (json, [from, to]) => json.replace(new RegExp(`steps\\.${from}\\b`, 'g'), `steps.${to}`), JSON.stringify(data)));
        setNodes((nds) => [...nds.map((n) => ({ ...n, selected: false })), ...clip.nodes.map((n) => toFlowNode({
            id: ids[n.id], type: n.type, selected: true, data: rewire(n.data), position: { x: n.position.x + shift.x, y: n.position.y + shift.y },
        }))]);
        setEdges((eds) => [...eds, ...clip.edges.map((e) => ({
            id: `e-${ids[e.source]}-${ids[e.target]}-${e.sourceHandle ?? 'out'}`, source: ids[e.source], target: ids[e.target], sourceHandle: e.sourceHandle, type: 'deletable',
        }))]);
        toast(clip.nodes.length === 1 ? 'Pasted 1 step.' : `Pasted ${clip.nodes.length} steps.`, 'success');
    };
    const copySteps = (picked) => {
        if (!picked.length) return;
        writeClipboard(clipOf(picked, edges));
        toast(picked.length === 1 ? `Copied "${picked[0].data?.label || picked[0].id}".` : `Copied ${picked.length} steps.`, 'success');
    };
    const duplicateSteps = (picked) => picked.length && pasteClip(clipOf(picked, edges));
    const selectAll = () => setNodes((nds) => nds.map((n) => ({ ...n, selected: true })));
    const deleteSteps = (picked) => deleteElements({ nodes: picked.filter((n) => n.id !== MAIN_TRIGGER) }); // onDelete offers Undo

    // ---- right-click menus and double-click ----------------------------------------------------
    const flowAt = (event) => {
        const p = screenToFlowPosition({ x: event.clientX, y: event.clientY });
        return { x: p.x - 48, y: p.y - 48 }; // a step is 96px square: centre it on the pointer
    };
    const openMenu = (event, items) => {
        event.preventDefault();
        setCreator(null);
        setMenu({ x: event.clientX, y: event.clientY, items });
    };
    const addStepAt = (position) => setCreator({ mode: 'action', position });
    const addFlowAt = (position) => setCreator({ mode: 'trigger', newFlow: true, position });

    const onPaneContextMenu = (event) => {
        const at = flowAt(event);
        const clip = readClipboard();
        openMenu(event, [
            !readOnly && { label: 'Add step here', icon: Plus, shortcut: 'Double-click', onClick: () => addStepAt(at) },
            !readOnly && { label: 'Add trigger here (new flow)', icon: Zap, onClick: () => addFlowAt(at) },
            !readOnly && { label: clip?.nodes?.length > 1 ? `Paste ${clip.nodes.length} steps` : 'Paste', icon: ClipboardPaste, shortcut: `${MOD}V`, disabled: !clip?.nodes?.length, onClick: () => pasteClip(clip, at) },
            'divider',
            { label: 'Select all', icon: SquareDashedMousePointer, shortcut: `${MOD}A`, onClick: selectAll },
            { label: 'Fit to screen', icon: Maximize, onClick: () => fitView({ duration: 250, padding: 0.3, maxZoom: 1.1 }) },
            !readOnly && 'divider',
            !readOnly && { label: 'Execute workflow', icon: FlaskConical, disabled: !!running, onClick: () => executeStep(null) },
        ]);
    };

    const onNodeContextMenu = (event, node) => {
        const picked = node.selected && selectedNodes().length > 1 ? selectedNodes() : [node];
        if (picked.length > 1) {
            return openMenu(event, [
                { label: `Copy ${picked.length} steps`, icon: Copy, shortcut: `${MOD}C`, onClick: () => copySteps(picked) },
                !readOnly && { label: `Duplicate ${picked.length} steps`, icon: CopyPlus, shortcut: `${MOD}D`, onClick: () => duplicateSteps(picked) },
                !readOnly && 'divider',
                !readOnly && { label: `Delete ${picked.filter((n) => n.id !== MAIN_TRIGGER).length} steps`, icon: Trash2, shortcut: 'Del', danger: true, onClick: () => deleteSteps(picked) },
            ]);
        }
        const isTrigger = node.type === 'trigger';
        const disabled = !!node.data?.disabled;
        openMenu(event, [
            { label: 'Open', icon: Settings2, onClick: () => openStep(node.id) },
            !readOnly && { label: isTrigger ? 'Execute this flow' : 'Execute step', icon: Play, disabled: !!running, onClick: () => executeStep(node.id) },
            !readOnly && 'divider',
            !readOnly && (node.type === 'branch'
                ? { label: 'Add step on "true"', icon: Plus, onClick: () => setCreator({ mode: 'action', from: node.id, handle: 'true' }) }
                : { label: 'Add step after', icon: Plus, onClick: () => setCreator({ mode: 'action', from: node.id, handle: null }) }),
            !readOnly && node.type === 'branch' && { label: 'Add step on "false"', icon: Plus, onClick: () => setCreator({ mode: 'action', from: node.id, handle: 'false' }) },
            !readOnly && isTrigger && { label: 'Change trigger', icon: Zap, onClick: () => setCreator({ mode: 'trigger', target: node.id }) },
            'divider',
            { label: 'Copy', icon: Copy, shortcut: `${MOD}C`, onClick: () => copySteps([node]) },
            !readOnly && { label: 'Duplicate', icon: CopyPlus, shortcut: `${MOD}D`, onClick: () => duplicateSteps([node]) },
            !readOnly && !isTrigger && { label: disabled ? 'Turn on' : 'Turn off (runs skip it)', icon: Power, onClick: () => toggleNode(node.id) },
            !readOnly && node.id !== MAIN_TRIGGER && 'divider',
            !readOnly && node.id !== MAIN_TRIGGER && { label: isTrigger ? 'Delete trigger' : 'Delete', icon: Trash2, shortcut: 'Del', danger: true, onClick: () => removeNode(node.id) },
        ]);
    };

    const onEdgeContextMenu = (event, edge) => openMenu(event, readOnly ? [{ label: 'Fit to screen', icon: Maximize, onClick: () => fitView({ duration: 250, padding: 0.3, maxZoom: 1.1 }) }] : [
        { label: 'Insert a step here', icon: BetweenHorizontalStart, onClick: () => setCreator({ mode: 'action', insert: edge }) },
        { label: 'Delete connection', icon: Unplug, danger: true, onClick: () => setEdges((eds) => eds.filter((e) => e.id !== edge.id)) },
    ]);

    const onSelectionContextMenu = (event, picked) => onNodeContextMenu(event, { ...picked[0], selected: true });

    // Double-click on empty canvas: add a step right there.
    const onCanvasDoubleClick = (event) => {
        if (readOnly || !event.target.classList?.contains('react-flow__pane')) return;
        addStepAt(flowAt(event));
    };

    // Keyboard: copy, paste, duplicate and select all (not while typing or with a panel open).
    useEffect(() => {
        const onKey = (e) => {
            if (!(e.metaKey || e.ctrlKey) || e.altKey || e.target.closest?.('input, textarea, select, [contenteditable="true"]') || openId || creator || menu) return;
            const key = e.key.toLowerCase();
            const picked = nodes.filter((n) => n.selected);
            if (key === 'c' && picked.length && !window.getSelection()?.toString()) copySteps(picked);
            else if (key === 'a') { e.preventDefault(); selectAll(); }
            else if (readOnly) return;
            else if (key === 'v' && readClipboard()?.nodes?.length) { e.preventDefault(); pasteClip(readClipboard()); }
            else if (key === 'd' && picked.length) { e.preventDefault(); duplicateSteps(picked); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

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
        // A trigger runs its whole flow; any other step runs with what feeds into it.
        const flow = nodeId && nodes.find((n) => n.id === nodeId)?.type === 'trigger';
        setRunning(!nodeId ? 'all' : flow ? descendants(edges, nodeId) : ancestors(edges, nodeId));
        try {
            const { data } = await window.axios.post(`/workflows/${initial.id}/test`, !nodeId ? {} : flow ? { trigger: nodeId } : { node: nodeId });
            setExecution(data.execution);
            if (!nodeId) setLogsOpen(true);
            const ok = data.execution.status === 'completed';
            toast(ok ? `${nodeId ? 'Step' : 'Workflow'} executed in ${duration(data.execution.duration_ms)}.` : 'Execution failed. See the red step.', ok ? 'success' : 'error');
        } catch (error) {
            toast(errorMessage(error), 'error');
        } finally {
            setRunning(null);
        }
    }, [persist, edges, nodes, initial.id]);

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
                sample = configured !== undefined ? { data: configured } : (id === MAIN_TRIGGER ? workflow.trigger_state : workflow.trigger_state?.nodes?.[id])?.sample;
            }
            return { id, label: node.data.label || id, icon: integration?.icon, integrationName: integration?.name, sample };
        }).filter(Boolean);
    }, [openNode, edges, nodes, catalogMap, runSteps, workflow.trigger_state]);

    const context = useMemo(() => ({
        catalog: catalogMap, runSteps: execution ? runSteps : {}, running, connected,
        openCreator: (opts) => setCreator({ mode: 'action', ...opts }),
        openNode: openStep, executeStep, removeNode, toggleNode, readOnly,
    }), [catalogMap, execution, runSteps, running, connected, openStep, executeStep, removeNode, toggleNode, readOnly]);

    const onDrop = (event) => event.preventDefault();

    // The step editor stays mounted while it plays its way out (frozen as it was), then unmounts.
    const openEditor = openNode ? (
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
            origin={origin}
        />
    ) : null;
    const lastEditor = useRef(null);
    if (openEditor) lastEditor.current = openEditor;
    const { mounted: editorMounted, closing: editorClosing } = usePresence(!!openNode, 240);
    const editor = editorMounted ? (openEditor ?? lastEditor.current) : null;

    return (
        <BuilderContext.Provider value={context}>
            <div className="flex h-dvh flex-col bg-bg" onPointerDownCapture={(e) => { lastPointer.current = [e.clientX, e.clientY]; }}>
                <header className="flex h-14 shrink-0 items-center gap-3 border-b border-line bg-surface px-3 sm:px-4">
                    <Link href="/" aria-label="FlowForge home"><Logo className="size-7" /></Link>
                    <nav className="flex min-w-0 items-center gap-1.5 text-14" aria-label="Breadcrumb">
                        <Link href="/workflows" className="hidden text-fg-muted hover:text-fg sm:inline">Workflows</Link>
                        <span className="hidden text-fg-subtle sm:inline">/</span>
                        <input value={name} onChange={(e) => setName(e.target.value)} aria-label="Workflow name" readOnly={readOnly}
                            className="min-w-0 truncate rounded-md bg-transparent px-1.5 py-1 text-base font-semibold sm:text-14 outline-none hover:bg-surface-2 focus:bg-surface-2 sm:w-72" />
                    </nav>
                    <span className={cn('hidden text-12h lg:inline', save.state === 'error' ? 'text-fail' : 'text-fg-subtle')} title={save.message} aria-live="polite">
                        {readOnly ? 'View only' : save.state === 'saving' ? 'Saving…' : save.state === 'error' ? 'Not saved' : 'Saved'}
                    </span>
                    <div className="mx-auto hidden md:block">
                        <Tabs value={tab} onChange={setTab} items={[{ value: 'editor', label: 'Editor' }, { value: 'executions', label: 'Executions' }]} />
                    </div>
                    <div className="ml-auto flex items-center gap-2.5 md:ml-0">
                        {can.ai && <Button size="sm" variant={aiOpen ? 'subtle' : 'secondary'} onClick={() => setAiOpen((o) => !o)}><AiIcon className="size-4 text-primary" />Ask AI</Button>}
                        <label className="hidden items-center gap-2 text-13 font-medium text-fg-muted md:flex" title="Retry failed runs automatically">
                            <RotateCw className="size-3.5" />Auto-retry
                            <Switch checked={retry.auto_retry} onChange={(v) => saveRetry({ auto_retry: v })} disabled={readOnly} label="Retry failed runs automatically" size="sm" />
                        </label>
                        <label className="flex items-center gap-2 text-13 font-medium text-fg-muted">
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
                                onNodeClick={(_, node) => openStep(node.id)}
                                onPaneContextMenu={onPaneContextMenu}
                                onNodeContextMenu={onNodeContextMenu}
                                onEdgeContextMenu={onEdgeContextMenu}
                                onSelectionContextMenu={onSelectionContextMenu}
                                onDoubleClick={onCanvasDoubleClick}
                                zoomOnDoubleClick={false}
                                onDelete={({ nodes: goneNodes, edges: goneEdges }) => offerUndo(goneNodes, goneEdges)}
                                onPaneClick={() => { setCreator(null); setMenu(null); }}
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
                                <Menu align="right" items={[
                                    { label: 'Add a step', icon: Plus, onClick: () => setCreator({ mode: 'action' }) },
                                    { label: 'Add a trigger (new flow)', icon: Zap, onClick: () => setCreator({ mode: 'trigger', newFlow: true }) },
                                ]} trigger={(
                                    <button type="button" title="Add a step or a new flow" aria-label="Add"
                                        className="ff-press flex size-10 items-center justify-center rounded-xl border border-line bg-surface text-fg shadow-card transition-[scale,border-color,color] hover:border-primary hover:text-primary motion-safe:active:scale-95">
                                        <Plus className="size-5" />
                                    </button>
                                )} />
                            </div>}
                            {!readOnly && nodes.length <= 2 && (
                                <p className="pointer-events-none absolute top-4 left-1/2 z-10 hidden -translate-x-1/2 rounded-full bg-surface/90 px-3 py-1 text-12 text-fg-subtle shadow-card md:block">
                                    Double-click to add a step. Right-click for more.
                                </p>
                            )}

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
                            <CanvasMenu menu={menu} onClose={closeMenu} />
                            {aiOpen && (
                                <aside className="animate-slide-in absolute inset-y-0 right-0 z-30 flex w-[min(420px,100%)] flex-col border-l border-line bg-surface shadow-pop" aria-label="AI builder">
                                    <div className="flex items-center gap-2 border-b border-line px-4 py-3">
                                        <AiIcon className="size-4 text-primary" />
                                        <h2 className="flex-1 text-14 font-semibold">Ask AI about this workflow</h2>
                                        {ai && <span className="truncate text-11h text-fg-subtle">{ai.model}</span>}
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
                                    <h2 className="text-13 font-semibold">Logs</h2>
                                    <StatusBadge status={execution.status} />
                                    <span className="text-12h text-fg-subtle">Run #{execution.id}, {execution.mode}, {duration(execution.duration_ms)}, {timeAgo(execution.created_at)}</span>
                                    {execution.error && <span className="hidden truncate text-12h text-fail xl:inline">{execution.error}</span>}
                                    <button type="button" onClick={() => setLogsOpen(false)} className="ml-auto rounded-md p-1 text-fg-muted hover:bg-surface-2 hover:text-fg" aria-label="Hide logs"><ChevronDown className="size-4" /></button>
                                </div>
                                <ExecutionDebug execution={execution} compact />
                            </section>
                        )}
                        {execution && !logsOpen && (
                            <button type="button" onClick={() => setLogsOpen(true)} className="flex h-9 shrink-0 items-center gap-2 border-t border-line bg-surface px-4 text-12h text-fg-muted hover:text-fg">
                                <ChevronUp className="size-4" />Logs<span className="text-fg-subtle">Run #{execution.id} {execution.status === 'completed' ? 'succeeded' : execution.status}, {timeAgo(execution.created_at)}</span>
                            </button>
                        )}
                    </div>
                )}
            </div>

            {editor && (editorClosing ? cloneElement(editor, { closing: true }) : editor)}

            <Dialog open={settingsOpen} onClose={() => setSettingsOpen(false)} title="Retries and errors" description="What happens when an app is busy, slow or down while this workflow runs.">
                <div className="space-y-4 text-13h">
                    <label className="flex items-start justify-between gap-4">
                        <span>
                            <span className="block font-semibold">Retry failed runs automatically</span>
                            <span className="mt-0.5 block text-12h text-fg-muted">When a run fails, FlowForge tries again from the step that failed: after 2 minutes, 10 minutes, 1 hour, 3 hours and 6 hours. Steps that already worked aren't repeated, and you only get the failure email if every try fails.</span>
                        </span>
                        <Switch checked={retry.auto_retry} onChange={(v) => saveRetry({ auto_retry: v })} disabled={readOnly} label="Retry failed runs automatically" />
                    </label>
                    {retry.auto_retry && (
                        <fieldset disabled={readOnly} className="space-y-1.5">
                            <legend className="mb-1.5 font-semibold">Which failures</legend>
                            {[['temporary', 'Only when an app is down, slow or busy', 'Recommended. A wrong key or missing field fails the same way every time, so it is not retried.'],
                                ['all', 'Every failure', 'Retries whatever went wrong, like Zapier\'s Autoreplay. Useful while you fix a credential or a setting.']].map(([value, label, hint]) => (
                                <label key={value} className={cn('flex cursor-pointer gap-2.5 rounded-xl border-2 px-3 py-2.5', retry.retry_on === value ? 'border-primary bg-primary-soft/40' : 'border-line hover:border-line-strong')}>
                                    <input type="radio" name="retry_on" value={value} checked={retry.retry_on === value} onChange={() => saveRetry({ retry_on: value })} className="mt-1 accent-[var(--primary)]" />
                                    <span>
                                        <span className="block font-medium">{label}</span>
                                        <span className="block text-12h text-fg-muted">{hint}</span>
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                    )}
                    <p className="rounded-lg bg-surface-2 px-3 py-2.5 text-12h text-fg-muted">Each step can also try again right away, keep going when it fails, or be turned off: open the step and look under <strong className="text-fg">When this step runs</strong>.</p>
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

    if (!runs) return <p className="flex flex-1 items-center justify-center gap-2 text-13 text-fg-muted"><Loader2 className="size-4 animate-spin" />Loading runs…</p>;
    if (runs.length === 0) return <p className="flex flex-1 items-center justify-center text-13 text-fg-muted">No runs yet. Execute the workflow from the editor, or activate it.</p>;

    return (
        <div className="flex min-h-0 flex-1">
            <ul className="w-72 shrink-0 overflow-y-auto border-r border-line bg-surface p-2">
                {runs.map((run) => (
                    <li key={run.id}>
                        <button type="button" onClick={() => setSelected(run.id)} aria-current={selected === run.id}
                            className={cn('w-full rounded-lg px-3 py-2.5 text-left', selected === run.id ? 'bg-primary-soft' : 'hover:bg-surface-2')}>
                            <span className="flex items-center justify-between gap-2">
                                <span className="text-13 font-medium">{new Date(run.created_at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })}</span>
                                <StatusBadge status={run.status} />
                            </span>
                            <span className="mt-0.5 block text-12 text-fg-subtle capitalize">#{run.id}, {run.mode}, {duration(run.duration_ms)}</span>
                        </button>
                    </li>
                ))}
            </ul>
            <div className="flex min-w-0 flex-1 flex-col bg-surface">
                {detail ? (
                    <>
                        <div className="flex items-center gap-3 border-b border-line px-5 py-3">
                            <h2 className="text-14 font-semibold">Run #{detail.id}</h2>
                            <StatusBadge status={detail.status} />
                            <Button size="sm" className="ml-auto" onClick={() => onOpenInEditor(detail)}>Show on canvas</Button>
                        </div>
                        <ExecutionDebug execution={detail} />
                    </>
                ) : <p className="flex flex-1 items-center justify-center gap-2 text-13 text-fg-muted"><Loader2 className="size-4 animate-spin" />Loading…</p>}
            </div>
        </div>
    );
}
