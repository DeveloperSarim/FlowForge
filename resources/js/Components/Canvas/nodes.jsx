import { createContext, memo, useContext } from 'react';
import { BaseEdge, EdgeLabelRenderer, getBezierPath, Handle, Position, useReactFlow } from '@xyflow/react';
import { AlertTriangle, Check, Play, Plus, Power, RotateCw, Settings2, Trash2, X, Zap } from 'lucide-react';
import IntegrationIcon from '@/Components/IntegrationIcon';
import { cn, duration } from '@/lib/utils';

/**
 * Shared builder state the custom nodes read: catalog lookup, last run results, which outputs are
 * already connected, and callbacks for the hover toolbar / "+" buttons.
 */
export const BuilderContext = createContext({
    catalog: {}, runSteps: {}, running: null, connected: new Set(),
    openCreator: () => {}, openNode: () => {}, executeStep: () => {}, removeNode: () => {}, readOnly: false,
});

function useNodeMeta(data) {
    const { catalog } = useContext(BuilderContext);
    const integration = catalog[data.integration];
    const operation = data.trigger
        ? integration?.triggers.find((t) => t.key === data.trigger)
        : integration?.actions.find((a) => a.key === data.action);
    const needsCredential = !!integration && integration.auth.length > 0 && !integration.auth_optional && !data.credential_id;
    return { integration, operation, needsCredential };
}

/** "+" stub on an output with nothing connected: click to pick the next step. */
function AddNext({ id, handle, top }) {
    const { openCreator, readOnly } = useContext(BuilderContext);
    if (readOnly) return null;
    return (
        <div className="nodrag absolute left-full flex -translate-y-1/2 items-center" style={{ top }}>
            <span className="h-px w-7 bg-[var(--edge)]" />
            <button type="button" onClick={(e) => { e.stopPropagation(); openCreator({ from: id, handle }); }} aria-label="Add next step"
                className="flex size-7 items-center justify-center rounded-lg border border-dashed border-line-strong bg-surface text-fg-muted shadow-card transition-colors hover:border-primary hover:bg-primary hover:text-primary-fg">
                <Plus className="size-4" />
            </button>
        </div>
    );
}

function Toolbar({ id, isTrigger, disabled }) {
    const { executeStep, openNode, removeNode, toggleNode, running, readOnly } = useContext(BuilderContext);
    const button = 'flex size-7 items-center justify-center rounded-md text-fg-muted hover:bg-surface-2 hover:text-fg';
    return (
        <div className="nodrag absolute -top-10 left-1/2 hidden -translate-x-1/2 items-center gap-0.5 rounded-lg border border-line bg-surface p-0.5 shadow-pop group-hover:flex">
            {!readOnly && <button type="button" className={button} title={isTrigger ? 'Execute this flow' : 'Execute step'} aria-label={isTrigger ? 'Execute this flow' : 'Execute step'} disabled={!!running} onClick={(e) => { e.stopPropagation(); executeStep(id); }}><Play className="size-3.5" /></button>}
            <button type="button" className={button} title="Open" aria-label="Open step" onClick={(e) => { e.stopPropagation(); openNode(id); }}><Settings2 className="size-3.5" /></button>
            {!isTrigger && !readOnly && toggleNode && (
                <button type="button" className={cn(button, disabled && 'text-primary')} title={disabled ? 'Turn this step on' : 'Turn this step off (runs skip it)'} aria-label={disabled ? 'Turn step on' : 'Turn step off'}
                    onClick={(e) => { e.stopPropagation(); toggleNode(id); }}><Power className="size-3.5" /></button>
            )}
            {id !== 'trigger' && !readOnly && <button type="button" className={cn(button, 'hover:text-fail')} title="Delete" aria-label="Delete step" onClick={(e) => { e.stopPropagation(); removeNode(id); }}><Trash2 className="size-3.5" /></button>}
        </div>
    );
}

function StatusDot({ step }) {
    if (!step || step.status === 'skipped') return null;
    const ok = step.status === 'success';
    return (
        <span title={`${ok ? 'Succeeded' : 'Failed'} in ${duration(step.duration_ms)}${step.http_status ? ` (HTTP ${step.http_status})` : ''}`}
            className={cn('absolute -right-2 -bottom-2 flex size-6 items-center justify-center rounded-full border-2 border-surface text-white', ok ? 'bg-ok' : 'bg-fail')}>
            {ok ? <Check className="size-3.5" strokeWidth={3} /> : <X className="size-3.5" strokeWidth={3} />}
        </span>
    );
}

function NodeFrame({ id, data, selected, shape, isTrigger, outputs, children }) {
    const { runSteps, running, connected } = useContext(BuilderContext);
    const { integration, operation, needsCredential } = useNodeMeta(data);
    const step = runSteps[id];
    const isRunning = running && (running === 'all' || running.includes?.(id));

    return (
        <div className="group relative">
            <Toolbar id={id} isTrigger={isTrigger} disabled={!!data.disabled} />
            <div className={cn(
                'relative flex size-24 items-center justify-center border-2 bg-surface shadow-card transition-[border-color,box-shadow,opacity]',
                shape,
                selected ? 'border-primary shadow-[0_0_0_4px_var(--primary-soft)]' : 'border-line hover:border-line-strong',
                step?.status === 'failed' && !selected && 'border-fail',
                step?.status === 'success' && !selected && 'border-ok/60',
                (step?.status === 'skipped' || data.disabled) && 'opacity-50',
                data.disabled && 'border-dashed',
                isRunning && 'animate-running',
            )}>
                {isTrigger && (
                    <span className="absolute top-1/2 -left-3 flex size-6 -translate-y-1/2 items-center justify-center rounded-full border-2 border-surface bg-warn text-white" aria-hidden>
                        <Zap className="size-3" fill="currentColor" />
                    </span>
                )}
                <IntegrationIcon icon={integration?.icon} name={integration?.name} bare className="[&_img]:size-12 [&_svg]:size-10" />
                {needsCredential && (
                    <span title="Select a credential for this step" className="absolute -top-2 -right-2 flex size-6 items-center justify-center rounded-full border-2 border-surface bg-warn text-white">
                        <AlertTriangle className="size-3" strokeWidth={2.5} />
                    </span>
                )}
                <StatusDot step={step} />
                {!data.disabled && data.retries > 0 && (
                    <span title={`Tries again up to ${data.retries} time${data.retries > 1 ? 's' : ''} if the app is busy or down`} className="absolute -top-2 -left-2 flex size-6 items-center justify-center rounded-full border-2 border-surface bg-surface-2 text-fg-muted">
                        <RotateCw className="size-3" strokeWidth={2.5} />
                    </span>
                )}
                {children}
            </div>
            {outputs.map(({ handle, top }) => !connected.has(`${id}:${handle ?? ''}`) && <AddNext key={handle ?? 'out'} id={id} handle={handle} top={top} />)}
            <div className="pointer-events-none absolute top-full left-1/2 mt-2 w-48 -translate-x-1/2 text-center">
                <p className="truncate text-13 leading-tight font-semibold">{data.label || operation?.name || 'Choose a step'}</p>
                <p className="mt-0.5 truncate text-11h text-fg-subtle">{data.disabled ? 'Turned off' : integration ? (operation?.name ?? 'Choose an action') : 'Unknown app'}</p>
            </div>
        </div>
    );
}

export const TriggerNode = memo(function TriggerNode({ id, data, selected }) {
    return (
        <NodeFrame id={id} data={data} selected={selected} isTrigger shape="rounded-l-[48px] rounded-r-[22px]" outputs={[{ handle: null, top: '50%' }]}>
            <Handle type="source" position={Position.Right} />
        </NodeFrame>
    );
});

export const ActionNode = memo(function ActionNode({ id, data, selected }) {
    return (
        <NodeFrame id={id} data={data} selected={selected} shape="rounded-[22px]" outputs={[{ handle: null, top: '50%' }]}>
            <Handle type="target" position={Position.Left} />
            <Handle type="source" position={Position.Right} />
        </NodeFrame>
    );
});

export const BranchNode = memo(function BranchNode({ id, data, selected }) {
    const { runSteps } = useContext(BuilderContext);
    const taken = runSteps[id]?.handle;
    return (
        <NodeFrame id={id} data={data} selected={selected} shape="rounded-[22px]" outputs={[{ handle: 'true', top: '30%' }, { handle: 'false', top: '70%' }]}>
            <Handle type="target" position={Position.Left} />
            {['true', 'false'].map((handle, i) => (
                <div key={handle}>
                    <Handle id={handle} type="source" position={Position.Right} style={{ top: i === 0 ? '30%' : '70%' }} />
                    <span className={cn('pointer-events-none absolute right-2.5 -translate-y-1/2 text-10h font-semibold', handle === 'true' ? 'text-ok' : 'text-fail', taken && taken !== handle && 'opacity-30')}
                        style={{ top: i === 0 ? '30%' : '70%' }}>{handle}</span>
                </div>
            ))}
        </NodeFrame>
    );
});

export const nodeTypes = { trigger: TriggerNode, action: ActionNode, branch: BranchNode };

/** Bezier edge with a delete button on hover/selection. */
export function DeletableEdge({ id, sourceX, sourceY, targetX, targetY, sourcePosition, targetPosition, markerEnd, style, selected }) {
    const { setEdges } = useReactFlow();
    const { readOnly } = useContext(BuilderContext);
    const [path, labelX, labelY] = getBezierPath({ sourceX, sourceY, sourcePosition, targetX, targetY, targetPosition });
    return (
        <>
            <BaseEdge id={id} path={path} markerEnd={markerEnd} style={style} interactionWidth={24} />
            <EdgeLabelRenderer>
                {!readOnly && <button type="button" aria-label="Delete connection" onClick={() => setEdges((eds) => eds.filter((e) => e.id !== id))}
                    className={cn('nodrag nopan absolute flex size-6 items-center justify-center rounded-md border border-line bg-surface text-fg-muted shadow-card hover:border-fail hover:text-fail',
                        selected ? 'opacity-100' : 'opacity-0 hover:opacity-100')}
                    style={{ transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)`, pointerEvents: 'all' }}>
                    <Trash2 className="size-3" />
                </button>}
            </EdgeLabelRenderer>
        </>
    );
}

export const edgeTypes = { deletable: DeletableEdge };
