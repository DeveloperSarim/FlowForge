import { useEffect, useMemo, useState } from 'react';
import { AlertCircle, Check, Minus, X } from 'lucide-react';
import DataView from '@/Components/DataView';
import IntegrationIcon from '@/Components/IntegrationIcon';
import { Badge, StatusBadge, Tabs } from '@/Components/ui';
import { cn, duration } from '@/lib/utils';

function httpTone(status) {
    if (!status) return 'neutral';
    return status < 300 ? 'ok' : status < 400 ? 'warn' : 'fail';
}

function StepState({ status }) {
    if (status === 'success') return <span className="flex size-4 items-center justify-center rounded-full bg-ok text-white"><Check className="size-2.5" strokeWidth={3.5} /></span>;
    if (status === 'failed') return <span className="flex size-4 items-center justify-center rounded-full bg-fail text-white"><X className="size-2.5" strokeWidth={3.5} /></span>;
    if (status === 'running') return <span className="flex size-4 items-center justify-center"><span className="size-2 animate-pulse rounded-full bg-info" /></span>;
    return <span className="flex size-4 items-center justify-center rounded-full bg-surface-3 text-fg-subtle"><Minus className="size-2.5" /></span>;
}

/**
 * Step-by-step view of one execution: what each node received, what it returned, and the HTTP call it made.
 */
export default function ExecutionDebug({ execution, compact = false }) {
    const steps = execution?.steps ?? [];
    const firstInteresting = useMemo(
        () => (steps.find((s) => s.status === 'failed') ?? [...steps].reverse().find((s) => s.status === 'success') ?? steps[0])?.node_id,
        [steps],
    );
    const [selected, setSelected] = useState(firstInteresting);
    const [tab, setTab] = useState('output');
    useEffect(() => setSelected(firstInteresting), [execution?.id, firstInteresting]);

    if (!execution) return null;
    const step = steps.find((s) => s.node_id === selected);

    return (
        <div className="flex min-h-0 flex-1 flex-col md:flex-row">
            <ol className={cn('shrink-0 overflow-y-auto border-line p-2 md:w-72 md:border-r', compact ? 'max-h-40 border-b md:max-h-none md:border-b-0' : 'border-b md:border-b-0')} aria-label="Steps">
                {execution.error && !compact && (
                    <li className="mb-2 flex gap-2 rounded-lg bg-fail-soft px-3 py-2.5 text-12h text-fail"><AlertCircle className="mt-px size-4 shrink-0" />{execution.error}</li>
                )}
                {steps.map((s) => (
                    <li key={s.node_id}>
                        <button type="button" onClick={() => setSelected(s.node_id)} aria-current={selected === s.node_id}
                            className={cn('flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-left transition-colors', selected === s.node_id ? 'bg-surface-2' : 'hover:bg-surface-2/60', s.status === 'skipped' && 'opacity-55')}>
                            <span className="relative">
                                <IntegrationIcon icon={s.icon} name={s.integration} size="sm" />
                                <span className="absolute -right-1 -bottom-1"><StepState status={s.status} /></span>
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-13 font-medium">{s.label}</span>
                                <span className="block truncate font-mono text-11 text-fg-subtle">{s.node_id}</span>
                            </span>
                            {s.status !== 'skipped' && s.type !== 'trigger' && <span className="text-11h text-fg-subtle tabular-nums">{duration(s.duration_ms)}</span>}
                        </button>
                    </li>
                ))}
            </ol>

            <div className="min-w-0 flex-1 overflow-y-auto p-5">
                {!step && <p className="text-13 text-fg-muted">Select a step to inspect it.</p>}
                {step && (
                    <>
                        <div className="mb-4 flex flex-wrap items-center gap-2">
                            <h3 className="mr-1 text-15 font-semibold">{step.label}</h3>
                            <StatusBadge status={step.status} />
                            {step.http_status && <Badge tone={httpTone(step.http_status)}>HTTP {step.http_status}</Badge>}
                            {step.handle && <Badge tone="info">Took the {step.handle} branch</Badge>}
                            {step.halted && <Badge tone="warn">Stopped by filter</Badge>}
                            {step.reused && <Badge tone="info">Done in an earlier try</Badge>}
                            {step.tries > 1 && <Badge tone="warn">Took {step.tries} tries</Badge>}
                            {step.continued && <Badge tone="warn">Failed, the run kept going</Badge>}
                            {step.uncertain && <Badge tone="warn">May have gone through: not retried automatically</Badge>}
                        </div>
                        {step.status === 'skipped' && (
                            <p className="text-13 text-fg-muted">{step.disabled ? 'This step is turned off, so the run skipped it and went on.' : step.type === 'trigger' ? 'Another trigger of this workflow started this run, so this flow didn\'t run.' : `This step didn't run: no path reached it${execution.status === 'failed' ? ', or an earlier step failed' : ''}.`}</p>
                        )}
                        {step.error && <p className="mb-4 flex gap-2 rounded-lg bg-fail-soft px-3 py-2.5 text-13 text-fail"><AlertCircle className="mt-px size-4 shrink-0" />{step.error}</p>}
                        {step.status !== 'skipped' && (
                            <>
                                {step.type !== 'trigger' && (
                                    <Tabs className="mb-4" value={tab} onChange={setTab} items={[
                                        { value: 'output', label: 'Output' }, { value: 'input', label: 'Input' }, ...(step.request ? [{ value: 'request', label: 'Request' }] : []),
                                    ]} />
                                )}
                                {(tab === 'output' || step.type === 'trigger') && <DataView value={step.output} empty="This step returned no data." />}
                                {tab === 'input' && step.type !== 'trigger' && <DataView value={step.input} empty="No inputs." />}
                                {tab === 'request' && step.request && (
                                    <div className="flex items-center gap-2 overflow-hidden rounded-lg border border-line bg-surface-2 px-3 py-2 font-mono text-12">
                                        <span className="font-semibold text-primary">{step.request.method}</span>
                                        <span className="break-all text-fg-muted">{step.request.url}</span>
                                    </div>
                                )}
                            </>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
